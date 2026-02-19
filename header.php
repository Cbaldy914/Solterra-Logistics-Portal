<?php
// Start the session if it's not already started
if (session_status() == PHP_SESSION_NONE) {
    session_name("logistics_session");
    // Ensure session cookies are sent only over HTTPS and are not accessible via JavaScript
    session_set_cookie_params([
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login?timeout=1");
    exit();
}

// Get the user's role from the session
$role = isset($_SESSION['role']) ? $_SESSION['role'] : null;
if ($role === null) {
    header("Location: login?timeout=1");
    exit();
}

// Get unread notification count
require_once __DIR__ . '/notification_helpers.php';
$unread_notifications = unread_notification_count($_SESSION['user_id']);
?>

<!-- Scoped, self-contained header styles (independent from portal.css) -->
<style>
/* --- CSS scope: .slp-header --- */
.slp-header * { box-sizing: border-box; }
.slp-header {
  --slp-bg: #ffffff;
  --slp-text: #1e2a32;
  --slp-muted: #5a6b75;
  --slp-accent: #488C9A;
  --slp-accent-dark: #3A6E7F;
  --slp-border: rgba(30, 42, 50, 0.08);
  position: sticky;
  top: 0;
  z-index: 1000;
  width: 100%;
  background: var(--slp-bg);
  border-bottom: 1px solid var(--slp-border);
  box-shadow: 0 6px 18px rgba(0,0,0,0.06);
  font-family: "Poppins", system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
}

.slp-header__inner {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px clamp(16px, 4vw, 28px);
  margin: 0 auto;
}

.slp-brand {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.slp-brand img {
  height: clamp(60px, 10vw, 85px);
  width: auto;
  display: block;
}

/* Nav toggle (hamburger) */
.slp-nav-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px; height: 42px;
  border-radius: 10px;
  border: 1px solid var(--slp-border);
  background: linear-gradient(180deg, #fff, #fafafa);
  color: var(--slp-accent);
  font-size: 22px;
  cursor: pointer;
  transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
}
.slp-nav-toggle { /* aligned per breakpoint */ }
.slp-nav-toggle:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
.slp-nav-toggle:active { transform: translateY(0); }

/* Desktop nav */
.slp-nav { position: relative; margin-left: auto; }
.slp-menu {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0; padding: 0; list-style: none;
}
.slp-item { position: relative; }
.slp-link {
  display: inline-flex; align-items: center; gap: 8px;
  color: var(--slp-text);
  text-decoration: none;
  padding: 10px 14px;
  border-radius: 10px;
  font-weight: 500; font-size: 15px;
  transition: color .2s ease, background .2s ease, transform .2s ease;
}
.slp-link:hover { color: var(--slp-accent); background: rgba(72,140,154,0.08); }

/* Primary action (Sign out visual) */
.slp-link--primary { background: var(--slp-accent); color: #fff; }
.slp-link--primary:hover { background: var(--slp-accent-dark); color: #fff; }

/* Sign out button in dropdown menus */
.slp-submenu .slp-signout {
  margin-top: 6px;
  padding-top: 10px;
  border-top: 1px solid var(--slp-border);
  color: #dc2626;
  font-weight: 600;
  text-align: center;
}
.slp-submenu .slp-signout:hover {
  background: rgba(220, 38, 38, 0.06);
  color: #b91c1c;
}

/* Profile pill */
.slp-profile {
  width: 40px; height: 40px; border-radius: 999px;
  background: linear-gradient(135deg, var(--slp-accent), var(--slp-accent-dark));
  color: #fff; display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
  box-shadow: 0 2px 10px rgba(72,140,154,0.28);
  position: relative;
}

/* Notification badge on profile */
.slp-profile-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: white;
  font-size: 11px;
  font-weight: 700;
  min-width: 20px;
  height: 20px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 6px;
  box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
  animation: pulse-badge 2s ease-in-out infinite;
  border: 2px solid var(--slp-bg);
}

@keyframes pulse-badge {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.1); }
}

/* Dropdown panel */
.slp-has-dropdown > .slp-submenu {
  position: absolute; inset: auto auto auto 0; top: calc(100% + 6px);
  min-width: 220px; padding: 10px;
  border-radius: 12px; border: 1px solid var(--slp-border);
  background: #fff;
  box-shadow: 0 16px 40px rgba(0,0,0,0.12);
  display: none;
  max-width: min(90vw, 320px);
}
.slp-align-right > .slp-submenu { left: auto; right: 0; }
.slp-nav .slp-item.slp-has-dropdown:last-child > .slp-submenu { left: auto; right: 0; }
.slp-has-dropdown::before {
  content: '';
  position: absolute;
  left: 0; right: 0; top: 100%;
  height: 10px; /* hover bridge to prevent flicker */
}
.slp-submenu a {
  display: block; padding: 10px 12px; border-radius: 8px;
  color: var(--slp-text); text-decoration: none; font-size: 14px; font-weight: 500;
}
.slp-submenu a:hover { background: rgba(72,140,154,0.08); color: var(--slp-accent); }

/* Hover/focus open on desktop */
@media (pointer:fine) {
  .slp-has-dropdown:hover > .slp-submenu { display: block; }
  .slp-has-dropdown:focus-within > .slp-submenu { display: block; }
}

/* Mobile layout */
@media (max-width: 1024px) {
  .slp-nav-toggle { display: inline-flex; margin-left: auto; }
  .slp-nav { margin-left: 0; }
  .slp-menu {
    position: fixed; left: 0; right: 0; top: calc(64px + 16px);
    background: #fff; border-top: 1px solid var(--slp-border);
    box-shadow: 0 20px 40px rgba(0,0,0,0.12);
    display: none; flex-direction: column; align-items: stretch;
    gap: 4px; padding: 10px 14px; max-height: 70vh; overflow-y: auto;
  }
  .slp-menu.is-open { display: flex; }
  .slp-item { width: 100%; }
  .slp-link { width: 100%; justify-content: space-between; padding: 12px 14px; }
  .slp-has-dropdown > .slp-submenu { position: static; display: none; box-shadow: none; border: none; padding: 6px 8px; }
  .slp-has-dropdown.is-open > .slp-submenu { display: block; }
}

/* Hide toggle on large screens */
@media (min-width: 1025px) { .slp-nav-toggle { display: none; } }
</style>

<div class="slp-header" role="banner">
  <div class="slp-header__inner">
    <a class="slp-brand" href="dashboard" aria-label="Solterra Solutions Home">
      <img src="pictures/header_logo.png" alt="Solterra Solutions" />
    </a>

    <div class="slp-nav">
      <ul id="slp-menu" class="slp-menu" role="menubar">
        <?php if ($role === 'global_admin'): ?>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Projects</a>
            <div class="slp-submenu" role="menu">
              <a href="dashboard" role="menuitem">Dashboard</a>
              <a href="add_project" role="menuitem">Add Project</a>
              <a href="manage_projects" role="menuitem">Manage Projects</a>
              <a href="module_cost_analysis" role="menuitem">Module Cost Analysis</a>
              <a href="project_planning" role="menuitem">Project Planning</a>
              <a href="admin_project_forecast" role="menuitem">Forecast Costs</a>
              <a href="project_site" role="menuitem">Link Project to Site</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Modules</a>
            <div class="slp-submenu" role="menu">
              <a href="modules" role="menuitem">Manage Modules</a>
              <a href="add_module_batch" role="menuitem">Add Modules</a>
              <a href="module_movements" role="menuitem">Module Movements</a>

              <a href="manage_deliveries" role="menuitem">Manage Deliveries</a>
              <a href="container_tracking" role="menuitem">Container ETA Tracker</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Warehouses</a>
            <div class="slp-submenu" role="menu">
              <a href="add_warehouse" role="menuitem">Add Warehouse</a>
              <a href="warehousing_overview" role="menuitem">Warehousing Overview</a>
              <a href="admin_warehouse_estimate" role="menuitem">Admin Warehouse Quote</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Manufacturers</a>
            <div class="slp-submenu" role="menu">
              <a href="manufacturer_overview" role="menuitem">Manufacturer Overview</a>
              <a href="add_manufacturer" role="menuitem">Add Manufacturer</a>
              <a href="manufacturers" role="menuitem">Manage Manufacturers</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Freight</a>
            <div class="slp-submenu" role="menu">
              <a href="admin_freight_estimates" role="menuitem">Freight Estimator</a>
              <a href="generate_bol" role="menuitem">Generate BOL</a>
            </div>
          </li>

          <li class="slp-item" role="none">
            <a href="admin_management" class="slp-link" role="menuitem">Users & Accounts</a>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Accounting</a>
            <div class="slp-submenu" role="menu">
              <a href="accounting" role="menuitem">Accounting Overview</a>
              <a href="add_invoice" role="menuitem">Invoices</a>
              <a href="generate_invoice" role="menuitem">Generate Invoice</a>
              <a href="accounts_payable" role="menuitem">Generate Payables</a>
              <a href="total_payables" role="menuitem">Total Payables</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown slp-align-right" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">
              <span class="slp-profile">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                <?php if ($unread_notifications > 0): ?>
                  <span class="slp-profile-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
              </span>
            </a>
            <div class="slp-submenu" role="menu">
              <a href="notifications" role="menuitem">
                Notifications 
                <?php if ($unread_notifications > 0): ?>
                  <span style="color: #ef4444; font-weight: 700;">(<?php echo $unread_notifications; ?>)</span>
                <?php endif; ?>
              </a>
              <a href="account_settings" role="menuitem">Profile</a>
              <a class="slp-signout" href="logout" role="menuitem">Sign Out</a>
            </div>
          </li>

        <?php elseif ($role === 'admin'): ?>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Projects</a>
            <div class="slp-submenu" role="menu">
              <a href="dashboard" role="menuitem">Dashboard</a>
              <a href="add_project" role="menuitem">Add Project</a>
              <a href="manage_projects" role="menuitem">Manage Projects</a>
              <a href="module_cost_analysis" role="menuitem">Cost Analysis</a>
              <a href="project_planning" role="menuitem">Project Planning</a>
              <a href="sustainability_overview" role="menuitem">Sustainability</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Modules</a>
            <div class="slp-submenu" role="menu">
              <a href="modules" role="menuitem">Manage Modules</a>
              <a href="add_module_batch" role="menuitem">Add Modules</a>
              <a href="module_movements" role="menuitem">Module Movements</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Warehousing</a>
            <div class="slp-submenu" role="menu">
              <a href="add_warehouse" role="menuitem">Add Warehouse</a>
              <a href="warehousing_overview" role="menuitem">Warehousing Overview</a>
              <a href="cost_estimate_calculator" role="menuitem">Cost Estimate Calculator</a>
              <a href="warehouse_optimization" role="menuitem">Warehouse Optimization (Beta)</a>
              <a href="admin_warehouse_estimate" role="menuitem">Warehouse Quotes</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Manufacturers</a>
            <div class="slp-submenu" role="menu">
              <a href="manufacturer_overview" role="menuitem">Manufacturer Overview</a>
              <a href="add_manufacturer" role="menuitem">Add Manufacturer</a>
              <a href="manufacturers" role="menuitem">Manage Manufacturers</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Shipments</a>
            <div class="slp-submenu" role="menu">
              <a href="create_shipment" role="menuitem">Create Shipment</a>
              <a href="manage_deliveries" role="menuitem">Manage Deliveries</a>
              <a href="container_tracking" role="menuitem">Container ETA Tracker</a>
              <a href="admin_freight_estimates" role="menuitem">Freight Estimates</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Documents</a>
            <div class="slp-submenu" role="menu">
              <a href="documents" role="menuitem">Project Documents</a>
              <a href="global_documents" role="menuitem">Global Documents</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown slp-align-right" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">
              <span class="slp-profile">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                <?php if ($unread_notifications > 0): ?>
                  <span class="slp-profile-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
              </span>
            </a>
            <div class="slp-submenu" role="menu">
              <a href="notifications" role="menuitem">
                Notifications 
                <?php if ($unread_notifications > 0): ?>
                  <span style="color: #ef4444; font-weight: 700;">(<?php echo $unread_notifications; ?>)</span>
                <?php endif; ?>
              </a>
              <a href="account_settings" role="menuitem">Profile</a>
              <a href="questions" role="menuitem">Questions & Support</a>
              <a href="invoices_all" role="menuitem">Invoices</a>
              <a class="slp-signout" href="logout" role="menuitem">Sign Out</a>
            </div>
          </li>

        <?php elseif ($role === 'customer_admin'): ?>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Projects</a>
            <div class="slp-submenu" role="menu">
              <a href="dashboard" role="menuitem">Dashboard</a>
              <a href="add_project" role="menuitem">Add Project</a>
              <a href="manage_projects" role="menuitem">Manage Projects</a>
              <a href="module_cost_analysis" role="menuitem">Cost Analysis</a>
              <a href="project_planning" role="menuitem">Project Planning</a>
              <a href="sustainability_overview" role="menuitem">Sustainability</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Modules</a>
            <div class="slp-submenu" role="menu">
              <a href="modules" role="menuitem">Manage Modules</a>
              <a href="add_module_batch" role="menuitem">Add Modules</a>
              <a href="module_movements" role="menuitem">Module Movements</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Warehousing</a>
            <div class="slp-submenu" role="menu">
              <a href="add_warehouse" role="menuitem">Add Warehouse</a>
              <a href="warehousing_overview" role="menuitem">Warehousing Overview</a>
              <a href="cost_estimate_calculator" role="menuitem">Cost Estimate Calculator</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Manufacturers</a>
            <div class="slp-submenu" role="menu">
              <a href="manufacturer_overview" role="menuitem">Manufacturer Overview</a>
              <a href="manufacturers" role="menuitem">Request Manufacturers</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Shipments</a>
            <div class="slp-submenu" role="menu">
              <a href="create_shipment" role="menuitem">Create Shipment</a>
              <a href="manage_deliveries" role="menuitem">Manage Deliveries</a>
              <a href="container_tracking" role="menuitem">Container ETA Tracker</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Documents</a>
            <div class="slp-submenu" role="menu">
              <a href="documents" role="menuitem">Project Documents</a>
              <a href="global_documents" role="menuitem">Global Documents</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown slp-align-right" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">
              <span class="slp-profile">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                <?php if ($unread_notifications > 0): ?>
                  <span class="slp-profile-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
              </span>
            </a>
            <div class="slp-submenu" role="menu">
              <a href="notifications" role="menuitem">
                Notifications
                <?php if ($unread_notifications > 0): ?>
                  <span style="color: #ef4444; font-weight: 700;">(<?php echo $unread_notifications; ?>)</span>
                <?php endif; ?>
              </a>
              <a href="account_settings" role="menuitem">Profile</a>
              <a href="questions" role="menuitem">Questions & Support</a>
              <a href="invoices_all" role="menuitem">Invoices</a>
              <a class="slp-signout" href="logout" role="menuitem">Sign Out</a>
            </div>
          </li>

        <?php elseif ($role === 'DDPm'): ?>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Projects</a>
            <div class="slp-submenu" role="menu">
              <a href="dashboard" role="menuitem">Dashboard</a>
              <a href="sustainability_overview" role="menuitem">Sustainability</a>
            </div>
          </li>

          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Warehousing</a>
            <div class="slp-submenu" role="menu">
              <a href="warehousing_overview" role="menuitem">Warehousing Overview</a>
              <a href="cost_estimate_calculator" role="menuitem">Cost Estimate Calculator</a>
              <a href="warehouse_optimization" role="menuitem">Warehouse Optimization (Beta)</a>
              <a href="warehouse_estimate" role="menuitem">Warehouse Quotes</a>
            </div>
          </li>

          <li class="slp-item" role="none"><a class="slp-link" href="freight_estimate" role="menuitem">Freight</a></li>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Documents</a>
            <div class="slp-submenu" role="menu">
              <a href="documents" role="menuitem">Project Documents</a>
              <a href="global_documents" role="menuitem">Global Documents</a>
            </div>
          </li>
          <li class="slp-item slp-has-dropdown slp-align-right" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">
              <span class="slp-profile">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                <?php if ($unread_notifications > 0): ?>
                  <span class="slp-profile-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
              </span>
            </a>
            <div class="slp-submenu" role="menu">
              <a href="notifications" role="menuitem">
                Notifications 
                <?php if ($unread_notifications > 0): ?>
                  <span style="color: #ef4444; font-weight: 700;">(<?php echo $unread_notifications; ?>)</span>
                <?php endif; ?>
              </a>
              <a href="account_settings" role="menuitem">Profile</a>
              <a href="questions" role="menuitem">Questions & Support</a>
              <a class="slp-signout" href="logout" role="menuitem">Sign Out</a>
            </div>
          </li>

        <?php else: ?>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Projects</a>
            <div class="slp-submenu" role="menu">
              <a href="dashboard" role="menuitem">Dashboard</a>
              <a href="module_cost_analysis" role="menuitem">Cost Analysis</a>
              <a href="sustainability_overview" role="menuitem">Sustainability</a>
            </div>
          </li>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Modules</a>
            <div class="slp-submenu" role="menu">
              <a href="modules" role="menuitem">Module Batches</a>
              <a href="module_movements" role="menuitem">Module Movements</a>
              <a href="manage_pallets" role="menuitem">View Pallets</a>
              <a href="container_tracking" role="menuitem">Container ETA Tracker</a>
            </div>
          </li>
          <li class="slp-item" role="none">
            <a href="manufacturer_overview" class="slp-link" role="menuitem">Manufacturers</a>
          </li>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Warehousing</a>
            <div class="slp-submenu" role="menu">
              <a href="warehousing_overview" role="menuitem">Warehousing Overview</a>
              <a href="cost_estimate_calculator" role="menuitem">Cost Estimate Calculator</a>
              <a href="warehouse_optimization" role="menuitem">Warehouse Optimization (Beta)</a>
              <a href="warehouse_estimate" role="menuitem">Warehouse Quotes</a>
            </div>
          </li>

          <li class="slp-item" role="none"><a class="slp-link" href="freight_estimate" role="menuitem">Freight</a></li>
          <li class="slp-item slp-has-dropdown" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">Documents</a>
            <div class="slp-submenu" role="menu">
              <a href="documents" role="menuitem">Project Documents</a>
              <a href="global_documents" role="menuitem">Global Documents</a>
            </div>
          </li>
          <li class="slp-item slp-has-dropdown slp-align-right" role="none">
            <a href="#" class="slp-link" role="menuitem" aria-haspopup="true" aria-expanded="false">
              <span class="slp-profile">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                <?php if ($unread_notifications > 0): ?>
                  <span class="slp-profile-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
              </span>
            </a>
            <div class="slp-submenu" role="menu">
              <a href="notifications" role="menuitem">
                Notifications 
                <?php if ($unread_notifications > 0): ?>
                  <span style="color: #ef4444; font-weight: 700;">(<?php echo $unread_notifications; ?>)</span>
                <?php endif; ?>
              </a>
              <a href="account_settings" role="menuitem">Profile</a>
              <a href="questions" role="menuitem">Questions & Support</a>
              <a href="invoices_all" role="menuitem">Invoices</a>
              <a class="slp-signout" href="logout" role="menuitem">Sign Out</a>
            </div>
          </li>
        <?php endif; ?>
      </ul>
    </div>

    <button class="slp-nav-toggle" aria-expanded="false" aria-controls="slp-menu" aria-label="Toggle navigation">&#9776;</button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.querySelector('.slp-nav-toggle');
  const menu = document.getElementById('slp-menu');
  const dropdownParents = Array.from(document.querySelectorAll('.slp-has-dropdown'));

  if (toggle && menu) {
    toggle.addEventListener('click', () => {
      const open = menu.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Mobile: turn dropdowns into accordions; Desktop: click toggles as fallback
  dropdownParents.forEach(parent => {
    const link = parent.querySelector('.slp-link');
    const submenu = parent.querySelector('.slp-submenu');
    if (!link || !submenu) return;

    link.addEventListener('click', (e) => {
      // Only intercept when link is a menu trigger
      if (link.getAttribute('href') === '#') e.preventDefault();

      // Close other open dropdowns on mobile
      const isMobile = window.matchMedia('(max-width: 1024px)').matches;
      if (isMobile) {
        dropdownParents.forEach(p => { if (p !== parent) p.classList.remove('is-open'); });
      }

      const willOpen = !parent.classList.contains('is-open');
      parent.classList.toggle('is-open', willOpen);
      link.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  // Click outside to close
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.slp-header')) {
      menu && menu.classList.remove('is-open');
      dropdownParents.forEach(p => p.classList.remove('is-open'));
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }
  });
});
</script>

<?php
// Include Sunny Chat Assistant Component for all roles
include_once __DIR__ . '/ai-assistant/components/sunny-chat.php';
?>
