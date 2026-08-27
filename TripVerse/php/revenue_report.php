<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <title>Booking Transactions | TripVerse Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.8.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        /* Enhanced Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar.collapsed .sidebar-text {
            display: none;
        }

        .sidebar nav {
            padding: 10px 0;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .sidebar nav a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .sidebar nav a:hover::before {
            left: 100%;
        }

        .sidebar nav a:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #3498db;
            color: #ffffff;
        }

        .sidebar nav a.active {
            background: linear-gradient(90deg, rgba(52, 152, 219, 0.2) 0%, transparent 100%);
            border-left-color: #3498db;
            color: #3498db;
            font-weight: 600;
        }

        .sidebar nav a .material-icons {
            margin-right: 15px;
            font-size: 20px;
            min-width: 24px;
            text-align: center;
        }

        /* User Menu Styles */
        .user-menu {
            position: relative;
        }

        .booking-toggle {
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 12px 20px;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }

        .booking-toggle:hover {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #e74c3c;
        }

        .booking-toggle .toggle-icon {
            margin-left: auto;
            transition: transform 0.3s ease;
            font-size: 18px;
        }

        .user-menu[aria-expanded="true"] .toggle-icon {
            transform: rotate(180deg);
        }

        /* Booking Submenu Styles */
        .booking-submenu {
            background: rgba(0, 0, 0, 0.2);
            border-left: 3px solid #3498db;
            margin-left: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
            max-height: 0;
        }

        .booking-submenu.show {
            max-height: 500px;
        }

        .booking-submenu.hidden {
            max-height: 0;
        }

        .booking-submenu a {
            padding: 10px 20px 10px 40px;
            font-size: 14px;
            border-left: none;
            position: relative;
        }

        .booking-submenu a::before {
            content: '';
            position: absolute;
            left: 25px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 4px;
            background: #bdc3c7;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .booking-submenu a:hover::before {
            background: #3498db;
            width: 6px;
            height: 6px;
        }

        .booking-submenu a:hover {
            background: rgba(255, 255, 255, 0.05);
            color: #3498db;
        }

        .booking-submenu a .material-icons {
            font-size: 16px;
            margin-right: 12px;
        }

        /* Category-specific colors */
        /* Business Intelligence */
        .sidebar nav a[href*="business"] .material-icons,
        .booking-toggle[data-target*="businessIntelligence"] .material-icons {
            color: #3498db;
        }

        /* Revenue Management */
        .sidebar nav a[href*="revenue"] .material-icons,
        .booking-toggle[data-target*="revenueManagement"] .material-icons {
            color: #2ecc71;
        }

        /* Operational Excellence */
        .sidebar nav a[href*="operational"] .material-icons,
        .booking-toggle[data-target*="operationalExcellence"] .material-icons {
            color: #e67e22;
        }

        /* Guest Analytics */
        .sidebar nav a[href*="guest"] .material-icons,
        .booking-toggle[data-target*="guestAnalytics"] .material-icons {
            color: #9b59b6;
        }

        /* Risk Management */
        .sidebar nav a[href*="risk"] .material-icons,
        .booking-toggle[data-target*="riskManagement"] .material-icons {
            color: #e74c3c;
        }

        /* Strategic Decisions */
        .sidebar nav a[href*="strategic"] .material-icons,
        .booking-toggle[data-target*="strategicDecisions"] .material-icons {
            color: #f1c40f;
        }

        /* Hotel Management */
        .sidebar nav a[href*="hotel"] .material-icons,
        .booking-toggle[data-target*="packageDropdown"] .material-icons {
            color: #1abc9c;
        }

        /* Profile Section */
        .profile-header {
            padding: 25px 20px;
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.9) 0%, rgba(52, 73, 94, 0.8) 100%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
            text-align: center;
        }

        .profile-photo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .profile-photo-container {
            position: relative;
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(52, 152, 219, 0.5);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-photo-container:hover {
            border-color: #3498db;
            transform: scale(1.05);
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .profile-photo-container:hover .profile-overlay {
            opacity: 1;
        }

        .profile-overlay .material-icons {
            color: white;
            font-size: 20px;
        }

        .profile-info h2 {
            margin: 0 0 5px 0;
            font-size: 16px;
            font-weight: 600;
            color: white;
        }

        .profile-info p {
            margin: 0 0 15px 0;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
        }

        /* User Dropdown */
        .user-dropdown {
            position: relative;
            width: 100%;
        }

        .user-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .dropdown-text {
            font-weight: 500;
        }

        .dropdown-arrow {
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .user-info[aria-expanded="true"] .dropdown-arrow {
            transform: rotate(180deg);
        }

        .dropdown-content {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            margin-top: 8px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }

        .dropdown-content.show {
            display: block;
            animation: dropdownFadeIn 0.2s ease;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f5f5f5;
            font-size: 14px;
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: #f8f9fa;
            color: #000;
        }

        .dropdown-item .material-icons {
            font-size: 18px;
            color: #666;
            width: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dropdown-item:hover .material-icons {
            color: #000;
        }

        .dropdown-content::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-bottom: 6px solid white;
            z-index: 1001;
        }

        .dropdown-content::after {
            content: '';
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-bottom: 7px solid #e0e0e0;
            z-index: 1000;
        }

        /* Scrollbar Styling */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar.collapsed {
                width: 100%;
            }
        }

        /* Animation for sidebar items */
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .sidebar nav a,
        .booking-toggle {
            animation: slideInLeft 0.3s ease forwards;
        }

        .sidebar nav a:nth-child(1) {
            animation-delay: 0.1s;
        }

        .sidebar nav a:nth-child(2) {
            animation-delay: 0.15s;
        }

        .sidebar nav a:nth-child(3) {
            animation-delay: 0.2s;
        }

        .sidebar nav a:nth-child(4) {
            animation-delay: 0.25s;
        }

        .sidebar nav a:nth-child(5) {
            animation-delay: 0.3s;
        }

        .sidebar nav a:nth-child(6) {
            animation-delay: 0.35s;
        }

        .sidebar nav a:nth-child(7) {
            animation-delay: 0.4s;
        }
    </style>

    <nav>
        <!-- Bagian yang tetap -->
        <a href="dashboard.php" class="active">
            <span class="material-icons">dashboard</span>
            <span>Dashboard DSS</span>
        </a>

        <!-- 1. BUSINESS INTELLIGENCE -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="businessIntelligence">
                <span class="material-icons">insights</span>
                <span>Business Intelligence</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="businessIntelligence">
                <a href="performance_overview.php">
                    <span class="material-icons">speed</span>
                    <span>Performance Overview</span>
                </a>
                <a href="market_analysis.php">
                    <span class="material-icons">analytics</span>
                    <span>Market Analysis</span>
                </a>
                <a href="competitive_analysis.php">
                    <span class="material-icons">leaderboard</span>
                    <span>Competitive Analysis</span>
                </a>
            </div>
        </div>

        <!-- 2. REVENUE MANAGEMENT -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="revenueManagement">
                <span class="material-icons">trending_up</span>
                <span>Revenue Management</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="revenueManagement">
                <a href="revenue_optimization.php">
                    <span class="material-icons">show_chart</span>
                    <span>Revenue Optimization</span>
                </a>
                <a href="pricing_strategy.php">
                    <span class="material-icons">price_change</span>
                    <span>Pricing Strategy</span>
                </a>
                <a href="forecasting.php">
                    <span class="material-icons">predictions</span>
                    <span>Demand Forecasting</span>
                </a>
                <a href="yield_management.php">
                    <span class="material-icons">bar_chart</span>
                    <span>Yield Management</span>
                </a>
            </div>
        </div>

        <!-- 3. OPERATIONAL EXCELLENCE -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="operationalExcellence">
                <span class="material-icons">business_center</span>
                <span>Operational Excellence</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="operationalExcellence">
                <a href="occupancy_analysis.php">
                    <span class="material-icons">hotel</span>
                    <span>Occupancy Analysis</span>
                </a>
                <a href="alos_analysis.php">
                    <span class="material-icons">schedule</span>
                    <span>ALOS Analysis</span>
                </a>
                <a href="room_utilization.php">
                    <span class="material-icons">meeting_room</span>
                    <span>Room Utilization</span>
                </a>
                <a href="staff_efficiency.php">
                    <span class="material-icons">groups</span>
                    <span>Staff Efficiency</span>
                </a>
            </div>
        </div>

        <!-- 4. GUEST ANALYTICS -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="guestAnalytics">
                <span class="material-icons">people</span>
                <span>Guest Analytics</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="guestAnalytics">
                <a href="guest_segmentation.php">
                    <span class="material-icons">diversity_3</span>
                    <span>Guest Segmentation</span>
                </a>
                <a href="loyalty_analysis.php">
                    <span class="material-icons">loyalty</span>
                    <span>Loyalty Analysis</span>
                </a>
                <a href="satisfaction_metrics.php">
                    <span class="material-icons">sentiment_satisfied</span>
                    <span>Satisfaction Metrics</span>
                </a>
                <a href="booking_patterns.php">
                    <span class="material-icons">pattern</span>
                    <span>Booking Patterns</span>
                </a>
            </div>
        </div>

        <!-- 5. RISK MANAGEMENT -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="riskManagement">
                <span class="material-icons">warning</span>
                <span>Risk Management</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="riskManagement">
                <a href="cancellation_analysis.php">
                    <span class="material-icons">cancel</span>
                    <span>Cancellation Analysis</span>
                </a>
                <a href="revenue_at_risk.php">
                    <span class="material-icons">money_off</span>
                    <span>Revenue at Risk</span>
                </a>
                <a href="seasonality_analysis.php">
                    <span class="material-icons">calendar_today</span>
                    <span>Seasonality Analysis</span>
                </a>
                <a href="risk_assessment.php">
                    <span class="material-icons">assistant_direction</span>
                    <span>Risk Assessment</span>
                </a>
            </div>
        </div>

        <!-- 6. STRATEGIC DECISIONS -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="strategicDecisions">
                <span class="material-icons">flag</span>
                <span>Strategic Decisions</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="strategicDecisions">
                <a href="investment_analysis.php">
                    <span class="material-icons">savings</span>
                    <span>Investment Analysis</span>
                </a>
                <a href="expansion_planning.php">
                    <span class="material-icons">map</span>
                    <span>Expansion Planning</span>
                </a>
                <a href="competitor_benchmarking.php">
                    <span class="material-icons">compare</span>
                    <span>Competitor Benchmarking</span>
                </a>
            </div>
        </div>

        <!-- Hotel Management (Existing) -->
        <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
            <a href="#" class="booking-toggle" data-target="packageDropdown">
                <span class="material-icons">home</span>
                <span>Hotel Management</span>
                <span class="material-icons toggle-icon">expand_more</span>
            </a>
            <div class="booking-submenu hidden" id="packageDropdown">
                <a href="package_hotels.php">
                    <span class="material-icons">add</span>
                    <span>Add Package Hotel</span>
                </a>
                <a href="reporting_hotels.php">
                    <span class="material-icons">list</span>
                    <span>Data Hotel</span>
                </a>
            </div>
        </div>

        <a href="logout.php">
            <span class="material-icons">logout</span>
            <span>Logout</span>
        </a>
    </nav>
    <script>
        // Enhanced Sidebar Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle functionality
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleSidebar');
            const mainContent = document.getElementById('main-content');

            // Load sidebar state from localStorage
            const sidebarState = localStorage.getItem('sidebarState');
            if (sidebarState === 'collapsed') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }

            // Toggle sidebar
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');

                    // Add animation effect
                    if (sidebar.classList.contains('collapsed')) {
                        showNotification('Sidebar collapsed', 'info');
                    } else {
                        showNotification('Sidebar expanded', 'info');
                    }
                });
            }

            // Enhanced dropdown menus for sidebar
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const parentMenu = toggle.closest('.user-menu');
                    const dropdownId = toggle.getAttribute('data-target');
                    const dropdown = document.getElementById(dropdownId);
                    const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                    // Close all other dropdowns first
                    document.querySelectorAll('.user-menu').forEach(menu => {
                        if (menu !== parentMenu) {
                            menu.setAttribute('aria-expanded', 'false');
                            const otherDropdown = document.getElementById(menu.querySelector('.booking-toggle').getAttribute('data-target'));
                            if (otherDropdown) {
                                otherDropdown.classList.remove('show');
                                otherDropdown.classList.add('hidden');
                                otherDropdown.setAttribute('aria-hidden', 'true');
                            }
                        }
                    });

                    // Toggle current dropdown
                    if (!isExpanded) {
                        parentMenu.setAttribute('aria-expanded', 'true');
                        dropdown.classList.remove('hidden');
                        dropdown.classList.add('show');
                        dropdown.setAttribute('aria-hidden', 'false');

                        // Add opening animation
                        dropdown.style.animation = 'slideDown 0.3s ease';
                    } else {
                        parentMenu.setAttribute('aria-expanded', 'false');
                        dropdown.classList.remove('show');
                        dropdown.classList.add('hidden');
                        dropdown.setAttribute('aria-hidden', 'true');
                    }
                });
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
            });

            // Profile dropdown functionality
            function toggleDropdown(button) {
                event.stopPropagation();
                const dropdown = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                // Close all other dropdowns first
                document.querySelectorAll('.dropdown-content').forEach(d => {
                    d.classList.remove('show');
                    d.setAttribute('aria-hidden', 'true');
                    d.previousElementSibling.setAttribute('aria-expanded', 'false');
                });

                // Toggle current dropdown
                if (!isExpanded) {
                    dropdown.classList.add('show');
                    button.setAttribute('aria-expanded', 'true');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            }

            // Close profile dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-dropdown')) {
                    document.querySelectorAll('.dropdown-content').forEach(d => {
                        d.classList.remove('show');
                        d.setAttribute('aria-hidden', 'true');
                        d.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            // Profile photo upload functionality
            const profilePhotoContainer = document.querySelector('.profile-photo-container');
            const profileUpload = document.getElementById('profileUpload');

            if (profilePhotoContainer) {
                profilePhotoContainer.addEventListener('click', function() {
                    profileUpload.click();
                });
            }

            if (profileUpload) {
                profileUpload.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        // Show loading state
                        const originalOverlay = profilePhotoContainer.querySelector('.profile-overlay');
                        const originalIcon = originalOverlay.innerHTML;
                        originalOverlay.innerHTML = '<i class="material-icons">hourglass_empty</i>';

                        // Simulate upload process
                        setTimeout(() => {
                            document.getElementById('uploadForm').submit();
                        }, 1000);
                    }
                });
            }

            // Handle image loading errors
            const profilePhoto = document.getElementById('profilePhoto');
            if (profilePhoto) {
                profilePhoto.addEventListener('error', function() {
                    this.src = '../images/default.jpg';
                });
            }

            // Active menu highlighting based on current page
            function highlightActiveMenu() {
                const currentPage = window.location.pathname.split('/').pop();
                const menuItems = document.querySelectorAll('.sidebar nav a');

                menuItems.forEach(item => {
                    const href = item.getAttribute('href');
                    if (href && currentPage.includes(href.replace('.php', ''))) {
                        item.classList.add('active');

                        // Also highlight parent dropdown if exists
                        const parentDropdown = item.closest('.booking-submenu');
                        if (parentDropdown) {
                            const dropdownId = parentDropdown.id;
                            const toggleButton = document.querySelector(`[data-target="${dropdownId}"]`);
                            if (toggleButton) {
                                toggleButton.closest('.user-menu').setAttribute('aria-expanded', 'true');
                                parentDropdown.classList.add('show');
                                parentDropdown.classList.remove('hidden');
                            }
                        }
                    } else {
                        item.classList.remove('active');
                    }
                });
            }

            // Initialize active menu highlighting
            highlightActiveMenu();

            // Keyboard navigation for sidebar
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    // Close all dropdowns when ESC is pressed
                    document.querySelectorAll('.user-menu').forEach(menu => {
                        menu.setAttribute('aria-expanded', 'false');
                    });
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        sub.classList.remove('show');
                        sub.classList.add('hidden');
                    });
                    document.querySelectorAll('.dropdown-content').forEach(dropdown => {
                        dropdown.classList.remove('show');
                    });
                }
            });

            // Smooth scrolling for sidebar
            const sidebarLinks = document.querySelectorAll('.sidebar nav a');
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href').startsWith('#')) {
                        e.preventDefault();
                        const targetId = this.getAttribute('href').substring(1);
                        const targetElement = document.getElementById(targetId);
                        if (targetElement) {
                            targetElement.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    }
                });
            });

            // Add hover effects with delay
            let hoverTimeout;
            sidebarLinks.forEach(link => {
                link.addEventListener('mouseenter', function() {
                    clearTimeout(hoverTimeout);
                    hoverTimeout = setTimeout(() => {
                        this.style.transform = 'translateX(5px)';
                    }, 100);
                });

                link.addEventListener('mouseleave', function() {
                    clearTimeout(hoverTimeout);
                    this.style.transform = 'translateX(0)';
                });
            });
        });

        // Utility function to show notifications
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.sidebar-notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `sidebar-notification notification-${type}`;
            notification.innerHTML = `
        <i class="material-icons">${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}</i>
        <span>${message}</span>
    `;

            // Add sidebar notification styles
            notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 20px;
        padding: 12px 16px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        animation: slideInLeft 0.3s ease-out;
        max-width: 300px;
    `;

            // Type-specific styles
            if (type === 'success') {
                notification.style.background = 'var(--success-color)';
            } else if (type === 'error') {
                notification.style.background = 'var(--danger-color)';
            } else {
                notification.style.background = 'var(--info-color)';
            }

            document.body.appendChild(notification);

            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOutLeft 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Additional CSS animations
        const additionalStyles = document.createElement('style');
        additionalStyles.textContent = `
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
    
    @keyframes slideOutLeft {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(-20px);
        }
    }
    
    .sidebar-notification {
        font-size: 14px;
    }
    
    .sidebar-notification .material-icons {
        font-size: 18px;
    }
    
    /* Mobile responsiveness */
    @media (max-width: 768px) {
        .sidebar {
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar-notification {
            left: 50%;
            transform: translateX(-50%);
            bottom: 80px;
        }
    }
    
    /* Print styles */
    @media print {
        .sidebar {
            display: none;
        }
    }
`;
        document.head.appendChild(additionalStyles);

        // Export functions for global access
        window.toggleDropdown = toggleDropdown;
        window.showNotification = showNotification;
    </script>