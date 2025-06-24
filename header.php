<?php
// Start the session if it's not already started
if (session_status() == PHP_SESSION_NONE) {
    session_name("logistics_session");
        // Ensure session cookies are sent only over HTTPS and are not accessible via JavaScript
        session_set_cookie_params([
            'secure' => true,
            'httponly' => true,
        ]);
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // User is not logged in
    header("Location: login");
    exit();
}

// Get the user's role from the session
if (isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
} else {
    // Handle the case where the role is not set in the session
    header("Location: login");
    exit();
}

// Now output the header HTML with the appropriate menu
?>
<header>
    <div class="header_logo">
        <?php if ($role === 'global_admin'): ?>
            <!-- Link to a "global admin" dashboard (you might use the same as admin_dashboard if you prefer) -->
            <a href="admin_dashboard">
                <img src="pictures/header_logo.png" alt="Solterra Solutions Logo">
            </a>

        <?php elseif ($role === 'admin'): ?>
            <!-- Local admin dashboard link -->
            <a href="admin_dashboard">
                <img src="pictures/header_logo.png" alt="Solterra Solutions Logo">
            </a>

        <?php else: ?>
            <!-- Regular user dashboard -->
            <a href="dashboard">
                <img src="pictures/header_logo.png" alt="Solterra Solutions Logo">
            </a>
        <?php endif; ?>
    </div>

    <nav>
        <button class="menu-toggle" aria-label="Toggle navigation">&#9776;</button>
        <ul class="menu">

            <?php if ($role === 'global_admin'): ?>
                <!-- =================================== -->
                <!-- GLOBAL ADMIN Navigation Menu       -->
                <!-- Full privileges across all accounts-->
                <!-- =================================== -->
                <li class="dropdown">
                    <a href="#" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="admin_dashboard">Dashboard</a>
                        <a href="add_project">Add Project</a>
                        <a href="manage_projects">Manage Projects</a>
                        <a href="module_cost_analysis">Module Cost Analysis</a>
                        <a href="admin_project_forecast">Forecast Costs</a>
                        <a href="project_site">Link Project to Site</a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Modules</a>
                    <div class="dropdown-content">
                        <a href="modules">Manage Modules</a>
                        <a href="module_movements">Module Movements</a>
                        <a href="manage_pallets">Manage Pallets</a>
                        <a href="manage_deliveries">Manage Deliveries</a>
                        <a href="link_pallet_deliveries">Link Pallets to Deliveries</a>

                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Warehouses</a>
                    <div class="dropdown-content">
                        <a href="add_warehouse">Add Warehouse</a>
                        <a href="manage_warehouses">Manage Warehouses</a>
                        <a href="admin_warehouse_estimate">Admin Warehouse Quote</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Manufacturers</a>
                    <div class="dropdown-content">
                        <a href="add_manufacturer">Add Manufacturer</a>
                        <a href="manufacturers">Manage Manufacturers</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Freight</a>
                    <div class="dropdown-content">
                        <a href="admin_freight_estimates">Freight Estimator</a>
                        <a href="generate_bol">Generate BOL</a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Users</a>
                    <div class="dropdown-content">
                        <a href="add_user">Add User</a>
                        <a href="manage_users">Manage Users</a>
                        <a href="add_account">Add Account</a>
                        <a href="manage_accounts">Manage Accounts</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Accounting</a>
                    <div class="dropdown-content">
                        <a href="accounting">Accounting Overview</a>
                        <a href="add_invoice">Invoices</a>
                        <a href="generate_invoice">Generate Invoice</a>
                        <a href="accounts_payable">Generate Payables</a>
                        <a href="total_payables">Total Payables</a>
                    </div>
                </li>
                <li><a href="logout" class="logout">Sign Out</a></li>

            <?php elseif ($role === 'admin'): ?>
                <!-- ============================= -->
                <!-- LOCAL ADMIN Navigation Menu  -->
                <!-- Manage only own account(s)   -->
                <!-- ============================= -->
                <li class="dropdown">
                    <a href="#" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="dashboard">Dashboard</a>
                        <a href="add_project">Add Project</a>
                        <a href="manage_projects">Manage Projects</a>
                        <a href="module_cost_analysis">Cost Analysis</a>
                        <a href="sustainability_overview">Sustainability</a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Modules</a>
                    <div class="dropdown-content">
                        <a href="modules">Manage Modules</a>
                        <a href="module_movements">Module Movements</a>
                        <a href="manage_pallets">Manage Pallets</a>
                        <a href="manage_deliveries">Manage Deliveries</a>
                        <a href="link_pallet_deliveries">Link Pallets to Deliveries</a>

                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Warehousing</a>
                    <div class="dropdown-content">
                        <a href="add_warehouse">Add Warehouse</a>
                        <a href="manage_warehouses">Manage Warehouses</a>
                        <a href="warehousing_overview">Warehousing Overview</a>
                        <a href="cost_estimate_calculator">Cost Estimate Calculator</a>
                        <a href="warehouse_optimization">Warehouse Optimization (Beta)</a>
                        <a href="warehouse_estimate">Warehouse Quotes</a>

                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Manufacturers</a>
                    <div class="dropdown-content">
                        <a href="add_manufacturer">Add Manufacturer</a>
                        <a href="manufacturers">Manage Manufacturers</a>
                    </div>
                </li>

                <li><a href="freight_estimate">Freight</a></li>
                <li><a href="documents">Documents</a></li>
                <li class="dropdown profile-dropdown">
                <!-- Avatar or icon that opens the dropdown -->
                    <a href="#" class="dropbtn profile-avatar"><span class="profile-initials"><?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?></span></a>
                    <div class="dropdown-content">
                        <a href="account_settings">Account Settings</a>
                        <a href="questions">Questions & Support</a>
                        <a href="invoices_all">Invoices</a>
                        <a href="logout" class="logout">Sign Out</a>
                    </div>
                </li>


            
            <?php elseif ($role === 'DDPm'): ?>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="dashboard">Dashboard</a>
                        <a href="sustainability_overview">Sustainability</a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Warehousing</a>
                    <div class="dropdown-content">
                        <a href="warehousing_overview">Warehousing Overview</a>
                        <a href="cost_estimate_calculator">Cost Estimate Calculator</a>
                        <a href="warehouse_optimization">Warehouse Optimization (Beta)</a>
                        <a href="warehouse_estimate">Warehouse Quotes</a>
                    </div>
                </li>

                <li><a href="freight_estimate">Freight</a></li>
                <li><a href="documents">Documents</a></li>
                <li><a href="questions">Questions</a></li>
                <li><a href="logout" class="logout">Sign Out</a></li>           
            <?php else: ?>
                <!-- ======================= -->
                <!-- REGULAR USER Navigation -->
                <!-- ======================= -->
                <li class="dropdown">
                    <a href="#" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="dashboard">Dashboard</a>
                        <a href="module_cost_analysis">Cost Analysis</a>
                        <a href="sustainability_overview">Sustainability</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Modules</a>
                    <div class="dropdown-content">
                        <a href="modules">Module Batches</a>
                        <a href="module_movements">Module Movements</a>
                        <a href="manage_pallets">View Pallets</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="dropbtn">Warehousing</a>
                    <div class="dropdown-content">
                        <a href="warehousing_overview">Warehousing Overview</a>
                        <a href="cost_estimate_calculator">Cost Estimate Calculator</a>
                        <a href="warehouse_optimization">Warehouse Optimization (Beta)</a>
                        <a href="warehouse_estimate">Warehouse Quotes</a>
                    </div>
                </li>

                <li><a href="freight_estimate">Freight</a></li>
                <li><a href="documents">Documents</a></li>
                <li class="dropdown profile-dropdown">
                <!-- Avatar or icon that opens the dropdown -->
                    <a href="#" class="dropbtn profile-avatar"><span class="profile-initials"><?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?></span></a>
                    <div class="dropdown-content">
                        <a href="account_settings">Account Settings</a>
                        <a href="questions">Questions & Support</a>
                        <a href="invoices_all">Invoices</a>
                        <a href="logout" class="logout">Sign Out</a>
                    </div>
                </li>

            <?php endif; ?>

        </ul>
    </nav>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const menu = document.querySelector('.menu');
    const dropdownLinks = document.querySelectorAll('.menu li.dropdown > a.dropbtn');

    menuToggle.addEventListener('click', function() {
        menu.classList.toggle('show');
    });

    // Handle dropdown interactions
    dropdownLinks.forEach(link => {
        const parentLi = link.parentElement;
        let hoverTimeout;

        // Mouse enter - show dropdown immediately
        parentLi.addEventListener('mouseenter', function() {
            clearTimeout(hoverTimeout);
            // Close other dropdowns first
            document.querySelectorAll('.menu li.dropdown.open').forEach(openDropdown => {
                if (openDropdown !== parentLi) {
                    openDropdown.classList.remove('open');
                }
            });
            parentLi.classList.add('open');
        });

        // Mouse leave - hide dropdown with small delay
        parentLi.addEventListener('mouseleave', function() {
            hoverTimeout = setTimeout(() => {
                parentLi.classList.remove('open');
            }, 150); // Small delay to allow cursor movement to dropdown
        });

        // Click handler as fallback (especially useful on mobile)
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const isOpen = parentLi.classList.contains('open');
            
            // Close all dropdowns first
            document.querySelectorAll('.menu li.dropdown.open').forEach(openDropdown => {
                openDropdown.classList.remove('open');
            });
            
            // Toggle this dropdown if it wasn't open
            if (!isOpen) {
                parentLi.classList.add('open');
            }
        });
    });

    // Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.menu li.dropdown.open').forEach(openDropdown => {
                openDropdown.classList.remove('open');
            });
        }
    });
});
</script>

<?php
// Include Sunny Chat Assistant Component
include_once __DIR__ . '/ai-assistant/components/sunny-chat.php';
?>

