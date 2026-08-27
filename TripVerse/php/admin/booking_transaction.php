<?php
session_start();

if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Check if user is admin (role is 'admin')
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';

$id_user = $_SESSION['id_user'];

// Get admin data - tanpa JOIN dengan tabel admin
$query = "SELECT 
            username,
            email,
            first_name,
            last_name,
            no_hp,
            gender,
            profile_picture
          FROM user
          WHERE id_user = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $mobile     = $data['no_hp'];
    $gender     = $data['gender'];
    $foto       = $data['profile_picture'] ?: '../../images/default.jpg';
    $bio        = ''; // Tidak ada bio karena tabel admin dihapus
} else {
    // Fallback if data not found
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = $bio = "-";
    $foto = "../../images/default.jpg";
}

// Get booking data with joins to get related information
$query = "SELECT 
            bh.booking_id,
            bh.customer_id,
            bh.customer_name,
            bh.hotel_id,
            bh.check_in,
            bh.check_out,
            bh.jumlah_kamar,
            bh.status,
            bh.tanggal_booking,
            bh.metode_pembayaran,
            bh.total_harga,
            h.nama_hotel,
            h.kota,
            t.nama_tipe,
            c.email AS customer_email,
            c.no_hp AS customer_phone,
            th.id_transaksi,
            tr.tanggal_transaksi,
            th.status AS payment_status
          FROM booking_hotel bh
          JOIN hotel h ON bh.hotel_id = h.hotel_id
          JOIN tipe_kamar t ON bh.tipe_id = t.tipe_id
          JOIN customer c ON bh.customer_id = c.customer_id
          LEFT JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
          LEFT JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
          ORDER BY bh.tanggal_booking DESC";

$bookings = $conn->query($query);

// Filter and search
$status_filter = $_GET['status'] ?? 'all';
$search_query = strtolower(trim($_GET['search'] ?? ''));

// Close connection
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <title>Booking Transactions | TripVerse Admin</title>
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.0.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        /* Booking Transaction Page Styles */
        .booking-section {
            padding: 25px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin: 25px;
        }

        .booking-section h1 {
            font-size: 26px;
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eaeff5;
            font-weight: 600;
        }

        .search-container {
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-form {
            display: flex;
            flex-grow: 1;
            position: relative;
            gap: 10px;
        }

        .search-input {
            flex-grow: 1;
            padding: 12px 18px;
            border: 1px solid #dfe6ee;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .search-input:focus {
            border-color: #4a90e2;
            outline: none;
            box-shadow: 0 2px 8px rgba(74, 144, 226, 0.2);
        }

        .search-btn,
        .filter-btn {
            padding: 12px 15px;
            background-color: #4a90e2;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }

        .search-btn:hover,
        .filter-btn:hover {
            background-color: #3a7bc8;
            transform: translateY(-1px);
        }

        .filter-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            border: 1px solid #e0e6ed;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            z-index: 100;
            width: 220px;
            margin-top: 5px;
        }

        .filter-dropdown.hidden {
            display: none;
        }

        .filter-dropdown label {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
            cursor: pointer;
            font-size: 14px;
            color: #4a5568;
            transition: color 0.2s;
        }

        .filter-dropdown label:hover {
            color: #2d3748;
        }

        .filter-dropdown input[type="radio"] {
            margin-right: 10px;
            accent-color: #4a90e2;
        }

        .table-responsive {
            overflow-x: auto;
            max-width: 100%;
            border-radius: 8px;
            border: 1px solid #eaeff5;
        }

        .booking-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 1300px;
        }

        .booking-table th {
            background-color: #f8fafc;
            padding: 14px 18px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }

        .booking-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            color: #4a5568;
        }

        .booking-table tr:hover {
            background-color: #f8fafc;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #718096;
            font-size: 15px;
        }

        .customer-info {
            display: flex;
            flex-direction: column;
        }

        .customer-info strong {
            color: #2d3748;
            font-weight: 500;
        }

        .customer-info small {
            color: #718096;
            font-size: 12px;
            margin-top: 3px;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            text-transform: capitalize;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }

        .status-badge.completed {
            background-color: #e6ffed;
            color: #28a745;
            border: 1px solid #b5f5c8;
        }

        .status-badge.confirmed {
            background-color: #ebf4ff;
            color: #1e88e5;
            border: 1px solid #c3dafe;
        }

        .status-badge.pending {
            background-color: #fffaf0;
            color: #fb8c00;
            border: 1px solid #fed7aa;
        }

        .status-badge.cancelled {
            background-color: #fff5f5;
            color: #e53e3e;
            border: 1px solid #fed7d7;
        }

        .transaction-info {
            display: flex;
            flex-direction: column;
        }

        .transaction-info small {
            color: #718096;
            font-size: 12px;
            margin-top: 3px;
        }

        .no-transaction {
            color: #a0aec0;
            font-style: italic;
            font-size: 12px;
        }

        .payment-method {
            font-weight: 500;
            color: #4a5568;
            white-space: nowrap;
        }

        .price-amount {
            font-weight: 600;
            color: #2b6cb0;
            white-space: nowrap;
        }

        .hotel-id {
            font-size: 12px;
            color: #718096;
            margin-top: 3px;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .booking-section {
                margin: 15px;
                padding: 20px;
            }
        }

        @media (max-width: 992px) {
            .search-container {
                flex-direction: column;
                align-items: stretch;
            }

            .search-form {
                flex-direction: column;
                gap: 10px;
            }

            .filter-dropdown {
                width: 100%;
                right: auto;
                left: 0;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="profile-header">
            <div class="profile-photo-container" style="position:relative; cursor:pointer;">
                <img src="<?php echo htmlspecialchars($foto); ?>" alt="Profile Photo" class="profile-photo" id="profilePhoto">
                <div class="profile-overlay">
                    <span class="material-icons">edit</span>
                </div>
                <form id="uploadForm" action="dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                    <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                </form>
            </div>

            <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
            <p><?php echo htmlspecialchars($email); ?></p>

            <div class="user-dropdown">
                <button class="user-info" aria-haspopup="true" aria-expanded="false">
                    <span class="material-icons">expand_more</span>
                </button>
                <div class="dropdown-content" role="menu" aria-hidden="true">
                    <a href="profile.php"><span class="material-icons">person</span> Profile</a>
                    <a href="../auth/logout.php"><span class="material-icons">logout</span> Logout</a>
                </div>
            </div>
        </div>

        <nav>
            <a href="dashboard.php"><span class="material-icons">dashboard</span><span>Dashboard</span></a>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="bookingDropdown1">
                    <span class="material-icons">home</span>
                    <span>Package Hotel</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="bookingDropdown1" role="menu" aria-hidden="true">
                    <a href="package_hotels.php"><span class="material-icons">hotel</span><span>Add Package Hotel</span></a>
                    <a href="reporting_hotels.php"><span class="material-icons">hotel</span><span>Data Hotel</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="bookingDropdown2">
                    <span class="material-icons">event</span>
                    <span>Reporting</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="bookingDropdown2" role="menu" aria-hidden="true">
                    <a href="booking_transaction.php" class="active"><span class="material-icons">money</span><span>Booking Transaction</span></a>
                    <a href="revenue_report.php"><span class="material-icons">bar_chart</span><span>Revenue Report</span></a>
                </div>
            </div>

            <a href="../auth/logout.php"><span class="material-icons">logout</span><span>Logout</span></a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <button id="toggleSidebar" class="menu-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>

            <div class="header-actions">
                <div class="notification-bell" id="notificationBell" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <span class="material-icons bell-icon">notifications</span>
                    <span class="notification-badge" id="notificationCount">0</span>
                </div>

                <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                    <img src="<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" id="headerUserAvatar" />
                </div>
            </div>
        </header>

        <?php if (isset($_SESSION['upload_notification'])) : ?>
            <div class="notification-message <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false ? 'success' : 'error'; ?>">
                <?php
                echo htmlspecialchars($_SESSION['upload_notification']);
                unset($_SESSION['upload_notification']);
                ?>
            </div>
        <?php endif; ?>

        <section class="booking-section">
            <h1>Booking Transactions (Filter: <?= htmlspecialchars($status_filter === 'all' ? 'All' : ucfirst($status_filter)); ?>)</h1>

            <div class="search-container">
                <form method="GET" action="" aria-label="Search bookings form" class="search-form">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by name, hotel or location..."
                        value="<?= htmlspecialchars($search_query); ?>"
                        aria-label="Search bookings"
                        class="search-input" />

                    <button type="submit" class="search-btn" aria-label="Search bookings">
                        <span class="material-icons">search</span>
                    </button>

                    <button type="button" id="filterToggle" class="filter-btn" title="Filter Status" aria-label="Toggle filter dropdown">
                        <span class="material-icons">filter_list</span>
                    </button>

                    <div id="filterDropdown" class="filter-dropdown hidden" role="group" aria-label="Filter status">
                        <label>
                            <input type="radio" name="status" value="all" <?= $status_filter === 'all' ? 'checked' : '' ?> onchange="this.form.submit()"> All
                        </label>
                        <label>
                            <input type="radio" name="status" value="Completed" <?= $status_filter === 'Completed' ? 'checked' : '' ?> onchange="this.form.submit()"> Completed
                        </label>
                        <label>
                            <input type="radio" name="status" value="Confirmed" <?= $status_filter === 'Confirmed' ? 'checked' : '' ?> onchange="this.form.submit()"> Confirmed
                        </label>
                        <label>
                            <input type="radio" name="status" value="Pending" <?= $status_filter === 'Pending' ? 'checked' : '' ?> onchange="this.form.submit()"> Pending
                        </label>
                        <label>
                            <input type="radio" name="status" value="Cancelled" <?= $status_filter === 'Cancelled' ? 'checked' : '' ?> onchange="this.form.submit()"> Cancelled
                        </label>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="booking-table">
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Hotel</th>
                            <th>Room Type</th>
                            <th>Location</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Rooms</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Transaction ID</th>
                            <th>Transaction Date</th>
                            <th>Payment Method</th>
                        </tr>
                    </thead>
                    <tbody id="bookingTableBody">
                        <?php if ($bookings->num_rows === 0): ?>
                            <tr>
                                <td colspan="14" class="no-data">No bookings found.</td>
                            </tr>
                        <?php else: ?>
                            <?php while ($booking = $bookings->fetch_assoc()):
                                // Apply filters
                                $matchStatus = $status_filter === 'all' || $booking['status'] === $status_filter;
                                $matchSearch = $search_query === '' ||
                                    strpos(strtolower($booking['customer_name']), $search_query) !== false ||
                                    strpos(strtolower($booking['nama_hotel']), $search_query) !== false ||
                                    strpos(strtolower($booking['kota']), $search_query) !== false;

                                if (!$matchStatus || !$matchSearch) continue;
                            ?>
                                <tr id="row-<?= $booking['booking_id'] ?>">
                                    <td><?= htmlspecialchars($booking['booking_id']); ?></td>
                                    <td><?= htmlspecialchars($booking['customer_id']); ?></td>
                                    <td>
                                        <div class="customer-info">
                                            <strong><?= htmlspecialchars($booking['customer_name']); ?></strong>
                                            <small><?= htmlspecialchars($booking['customer_email']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="customer-info">
                                            <strong><?= htmlspecialchars($booking['nama_hotel']); ?></strong>
                                            <small class="hotel-id">ID: <?= htmlspecialchars($booking['hotel_id']); ?></small>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($booking['nama_tipe']); ?></td>
                                    <td><?= htmlspecialchars($booking['kota']); ?></td>
                                    <td><?= date('d M Y', strtotime($booking['check_in'])); ?></td>
                                    <td><?= date('d M Y', strtotime($booking['check_out'])); ?></td>
                                    <td><?= htmlspecialchars($booking['jumlah_kamar']); ?></td>
                                    <td class="price-amount">Rp <?= number_format($booking['total_harga'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="status-badge <?= strtolower($booking['status']); ?>">
                                            <?= htmlspecialchars($booking['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= !empty($booking['id_transaksi']) ? htmlspecialchars($booking['id_transaksi']) : '<span class="no-transaction">N/A</span>'; ?>
                                    </td>
                                    <td>
                                        <?= !empty($booking['tanggal_transaksi']) ? date('d M Y', strtotime($booking['tanggal_transaksi'])) : '<span class="no-transaction">N/A</span>'; ?>
                                    </td>
                                    <td class="payment-method">
                                        <?= !empty($booking['metode_pembayaran']) ? htmlspecialchars($booking['metode_pembayaran']) : '<span class="no-transaction">N/A</span>'; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        // Toggle sidebar
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'collapsed') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
        });

        // Profile photo upload
        document.querySelector('.profile-photo-container').addEventListener('click', () => {
            document.getElementById('profileUpload').click();
        });

        document.getElementById('profileUpload').addEventListener('change', () => {
            document.getElementById('uploadForm').submit();
        });

        // Dropdown menus
        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const parentMenu = toggle.closest('.user-menu');
                const dropdownId = toggle.getAttribute('data-target');
                const dropdown = document.getElementById(dropdownId);
                const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                // Close all other dropdowns
                document.querySelectorAll('.user-menu').forEach(menu => {
                    menu.setAttribute('aria-expanded', 'false');
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    sub.classList.remove('show');
                    sub.classList.add('hidden');
                    sub.setAttribute('aria-hidden', 'true');
                });

                // Toggle current menu
                if (!isExpanded) {
                    parentMenu.setAttribute('aria-expanded', 'true');
                    dropdown.classList.remove('hidden');
                    dropdown.classList.add('show');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            });
        });

        // Toggle filter dropdown
        document.getElementById('filterToggle').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('filterDropdown').classList.toggle('hidden');
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.user-menu')) {
                document.querySelectorAll('.user-menu').forEach(menu => {
                    menu.setAttribute('aria-expanded', 'false');
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    sub.classList.remove('show');
                    sub.classList.add('hidden');
                    sub.setAttribute('aria-hidden', 'true');
                });
            }

            if (!e.target.closest('.search-container')) {
                document.getElementById('filterDropdown').classList.add('hidden');
            }
        });
    </script>
</body>

</html>