<?php
// Start the session if it's not already started
if (session_status() == PHP_SESSION_NONE) {
    session_name("logistics_session");
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
                    <a href="admin_dashboard" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="add_project">Add Project</a>
                        <a href="manage_projects">Manage Projects</a>
                        <a href="manage_unassigned_modules">Unassigned Modules</a>
                        <a href="module_assignments">Module Assignments</a>
                        <a href="module_cost_analysis">Module Cost Analysis</a>
                        <a href="admin_project_forecast">Forecast Costs</a>
                        <a href="project_site">Link Project to Site</a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="#" class="dropbtn">Warehouses</a>
                    <div class="dropdown-content">
                        <a href="warehouses">Warehouses</a>
                        <a href="admin_warehouse_estimate">Admin Warehouse Quote</a>
                    </div>
                </li>

                <li><a href="warehouse_optimization">Test</a></li>

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

                <li><a href="add_invoice">Invoices</a></li>
                <li><a href="logout" class="logout">Sign Out</a></li>

            <?php elseif ($role === 'admin'): ?>
                <!-- ============================= -->
                <!-- LOCAL ADMIN Navigation Menu  -->
                <!-- Manage only own account(s)   -->
                <!-- ============================= -->
                <li class="dropdown">
                    <a href="dashboard" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="future_projects">Future Projects</a>
                        <a href="module_cost_analysis">Cost Analysis</a>
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
                    </div>
                </li>

            
            <?php elseif ($role === 'DDPm'): ?>
                <li class="dropdown">
                    <a href="dashboard" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="future_projects">Future Projects</a>
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
                    <a href="dashboard" class="dropbtn">Projects</a>
                    <div class="dropdown-content">
                        <a href="future_projects">Future Projects</a>
                        <a href="module_cost_analysis">Cost Analysis</a>
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

    // For mobile: toggle dropdowns on click
    dropdownLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                const parentLi = this.parentElement;
                parentLi.classList.toggle('open');
            }
        });
    });
});
</script>
