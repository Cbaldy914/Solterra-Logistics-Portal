<?php
require_once 'components/project_overview/data_processing.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Overview - <?php echo htmlspecialchars($project['project_name']); ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="components/project_overview/project_overview.css?v=<?php echo time(); ?>">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
</head>
<body>
<script>
// Keep unit filter buttons on one line on mobile by shortening Truckloads to Trucks
document.addEventListener('DOMContentLoaded', function() {
  function updateTruckloadsLabel() {
    const isMobile = window.innerWidth <= 768;
    document.querySelectorAll('.unit-filter-btn[data-unit="truckloads"]').forEach(btn => {
      const desired = isMobile ? 'Trucks' : 'Truckloads';
      if (btn.textContent.trim() !== desired) btn.textContent = desired;
    });
  }
  updateTruckloadsLabel();
  window.addEventListener('resize', updateTruckloadsLabel);
});

// Tap-to-reveal full table header on mobile
document.addEventListener('DOMContentLoaded', function() {
  if (window.innerWidth <= 768) {
    document.querySelectorAll('.table-responsive th[data-full]').forEach(function(th) {
      th.setAttribute('title', th.getAttribute('data-full'));
      th.style.cursor = 'pointer';
      th.addEventListener('click', function() {
        // Show full name above header row temporarily
        const label = document.createElement('div');
        label.textContent = th.getAttribute('data-full');
        label.style.position = 'absolute';
        label.style.top = '-24px';
        label.style.left = '0';
        label.style.right = '0';
        label.style.textAlign = 'center';
        label.style.fontSize = '12px';
        label.style.fontWeight = '600';
        label.style.color = '#293E4C';
        label.style.background = 'rgba(255,255,255,0.9)';
        label.style.padding = '2px 4px';
        label.style.borderRadius = '4px';
        label.style.boxShadow = '0 2px 6px rgba(0,0,0,0.1)';
        th.style.position = 'relative';
        th.appendChild(label);
        setTimeout(function(){ label.remove(); }, 1500);
      });
    });
  }
});
</script>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    $from_page = isset($_GET['from']) ? $_GET['from'] : '';

    if ($from_page === 'module_movements') {
        // Show breadcrumb back to Module Movements
        echo slp_render_breadcrumbs([
            'current_label' => $project['project_name'],
            'extra' => [ ['label' => 'Module Movements', 'url' => 'module_movements.php?project_id=' . (int)$project_id] ]
        ]);
    } else {
        echo slp_render_breadcrumbs([
            'project_id'  => (int)$project_id,
            'omit_current'=> true,
        ]);
    }

    // Calculate values for the header
    $remaining_mw = $project_size_mw - $ordered_mw;
    $order_progress_pct = $project_size_mw > 0 ? min(100, ($ordered_mw / $project_size_mw) * 100) : 0;
    $can_add_modules = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin']);
    $projectImage = !empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png';
    $planning_badge = null;
    $has_projection = !empty($primary_projection);
    $has_legs_with_dates = false;
    $has_modules_only = false;
    if ($has_projection) {
        if (!empty($primary_projection['legs'])) {
            foreach ($primary_projection['legs'] as $_leg) {
                if (!empty($_leg['start_date']) && strtotime($_leg['start_date']) > 0) {
                    $has_legs_with_dates = true;
                    break;
                }
            }
        }
        if (!$has_legs_with_dates) {
            $has_modules_only = !empty($primary_projection['module_allocations']);
        }
    }

    if ($has_legs_with_dates) {
        $planning_badge = [
            'class' => 'has-forecast',
            'icon' => 'fas fa-check-circle',
            'label' => 'Plan Set',
            'title' => 'View or edit delivery plan'
        ];
    } elseif ($has_modules_only) {
        $planning_badge = [
            'class' => 'partial-forecast',
            'icon' => 'fas fa-clock',
            'label' => 'In Progress',
            'title' => 'Plan partially configured - add routing details'
        ];
    } else {
        $planning_badge = [
            'class' => 'no-forecast',
            'icon' => 'fas fa-plus-circle',
            'label' => 'Add Plan',
            'title' => 'Create a delivery plan'
        ];
    }
    ?>

    <!-- Redesigned Project Header -->
    <div class="project-header-redesign">
        <!-- Header Content -->
        <div class="project-header-content">
            <div class="project-header-left">
                <div class="project-header-icon">
                    <img src="<?php echo htmlspecialchars($projectImage); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])): ?>
                    <a class="project-photo-edit-btn" href="project_photos.php?project_id=<?php echo $project_id; ?>" onclick="event.stopPropagation();">
                        Photos
                    </a>
                    <?php endif; ?>
                </div>
                <div class="project-header-info">
                    <div class="project-title-row">
                        <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
                        <?php if ($project_health !== 'on_track' || $is_manual_health): ?>
                        <div class="health-indicator health-<?php echo $project_health; ?><?php echo $is_manual_health ? ' is-manual' : ''; ?><?php echo $can_add_modules ? ' clickable' : ''; ?>"
                             <?php if ($can_add_modules): ?>onclick="openHealthModal()"<?php endif; ?>
                             data-tooltip="<?php echo htmlspecialchars($project_health_reason); ?>">
                            <span class="health-dot"></span>
                            <span class="health-text"><?php echo $project_health_text; ?></span>
                            <?php if ($is_manual_health): ?>
                            <span class="health-manual-badge" title="Manually set<?php echo $manual_health_set_by_name ? ' by ' . htmlspecialchars($manual_health_set_by_name) : ''; ?><?php echo $manual_health_set_at_formatted ? ' on ' . htmlspecialchars($manual_health_set_at_formatted) : ''; ?>">Manual</span>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($can_add_modules): ?>
                        <div class="health-indicator health-on_track clickable" onclick="openHealthModal()" data-tooltip="<?php echo htmlspecialchars($project_health_reason ?: 'Project is on track'); ?>">
                            <span class="health-dot"></span>
                            <span class="health-text"><?php echo $project_health_text; ?></span>
                        </div>
                        <?php else: ?>
                        <div class="health-indicator health-on_track" data-tooltip="<?php echo htmlspecialchars($project_health_reason ?: 'Project is on track'); ?>">
                            <span class="health-dot"></span>
                            <span class="health-text"><?php echo $project_health_text; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])): ?>
                        <div class="project-header-actions">
                            <button class="project-settings-btn" type="button" onclick="toggleProjectActions(); event.stopPropagation();" title="Project Actions">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="3"></circle>
                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                </svg>
                            </button>
                            <div class="project-settings-dropdown" id="projectActionsDropdown">
                                <a href="edit_project.php?project_id=<?php echo $project_id; ?>">Edit Project</a>
                                <a href="#" class="danger" onclick="confirmDeleteProject(<?php echo $project_id; ?>, '<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>')">Delete Project</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="project-header-subtitle"><?php echo htmlspecialchars($project['project_address']); ?></p>
                    <?php if (!empty($planning_badge)): ?>
                    <div class="project-header-subtitle project-planning-row">
                        <span class="project-planning-label">Project Planning:</span>
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>"
                           class="forecast-badge project-planning-badge <?php echo $planning_badge['class']; ?>"
                           title="<?php echo htmlspecialchars($planning_badge['title']); ?>">
                            <i class="<?php echo $planning_badge['icon']; ?>"></i>
                            <span><?php echo $planning_badge['label']; ?></span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="project-header-stats">
                <!-- Domestic Content (always visible) -->
                <div class="project-stat-dc <?php echo $dc_overall_pct !== null ? '' : 'dc-not-tracked'; ?>" onclick="<?php echo $dc_overall_pct !== null ? 'openDomesticContentModal()' : ''; ?>; event.stopPropagation();">
                    <?php if ($dc_overall_pct !== null): ?>
                    <span class="stat-dc-number"><?php echo number_format($dc_overall_pct, 1); ?>%</span>
                    <?php else: ?>
                    <span class="stat-dc-number dc-na-value">N/A</span>
                    <?php endif; ?>
                    <span class="stat-dc-label">Domestic Content</span>
                    <?php if ($dc_overall_pct !== null): ?>
                    <svg class="stat-dc-icon" width="12" height="12" viewBox="0 0 12 12"><path d="M2 10L10 2M10 2H4M10 2V8" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?php endif; ?>
                </div>

                <!-- Order Progress -->
                <div class="project-stat-order" onclick="openOrderBreakdownModal(); event.stopPropagation();">
                    <?php if ($project_size_mw > 0): ?>
                    <div class="stat-order-ring">
                        <svg width="54" height="54">
                            <circle cx="27" cy="27" r="22" fill="none" stroke="#e9ecef" stroke-width="4.5"/>
                            <circle cx="27" cy="27" r="22" fill="none"
                                stroke="<?php echo $order_progress_pct >= 100 ? '#28a745' : '#488C9A'; ?>"
                                stroke-width="4.5" stroke-linecap="round"
                                stroke-dasharray="138.2"
                                stroke-dashoffset="<?php echo 138.2 - (138.2 * min(100, $order_progress_pct) / 100); ?>"
                                transform="rotate(-90 27 27)"/>
                        </svg>
                        <span class="stat-order-ring-pct"><?php echo number_format($order_progress_pct, 0); ?>%</span>
                    </div>
                    <?php endif; ?>
                    <div class="stat-order-details">
                        <div class="stat-order-mw">
                            <span class="stat-mw-value"><?php echo number_format($ordered_mw, 2); ?></span>
                            <span class="stat-mw-label">ordered</span>
                            <span class="stat-mw-sep">/</span>
                            <span class="stat-mw-total"><?php echo number_format($project_size_mw, 2); ?></span>
                            <span class="stat-mw-unit">MW</span>
                        </div>
                        <div class="stat-order-meta">
                            <span><?php echo number_format($total_raw_modules); ?> modules</span>
                            <?php if ($remaining_mw > 0): ?>
                            <span class="stat-badge stat-badge-warning"><?php echo number_format($remaining_mw, 2); ?> MW remaining</span>
                            <?php elseif ($remaining_mw < 0): ?>
                            <span class="stat-badge stat-badge-success">+<?php echo number_format(abs($remaining_mw), 2); ?> MW over</span>
                            <?php else: ?>
                            <span class="stat-badge stat-badge-success">On target</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <svg class="stat-order-expand" width="12" height="12" viewBox="0 0 12 12"><path d="M2 10L10 2M10 2H4M10 2V8" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            </div>
        </div>

        <!-- Quick Navigation -->
        <div class="quick-nav-label">Quick Links</div>
        <div class="project-quick-nav">
            <!-- Modules Dropdown -->
            <div class="nav-dropdown">
                <button class="nav-dropdown-btn" onclick="toggleNavDropdown(event, 'modulesDropdown')" title="Batch setup, palletization & movements">
                    <i class="fas fa-cubes"></i> Modules <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 4L5 7L8 4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
                <div class="nav-dropdown-content" id="modulesDropdown">
                    <a href="module_movements.php?project_id=<?php echo $project_id; ?>">Module Movements</a>
                    <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Module Overview</a>
                    <a href="<?php echo $isAdmin ? 'create_shipment.php' : 'manage_pallets.php'; ?>?project_id=<?php echo $project_id; ?>"><?php echo $isAdmin ? 'Manage Pallets' : 'View Pallets'; ?></a>
                    <?php if ($can_add_modules): ?>
                    <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">Add Modules</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Deliveries Dropdown -->
            <div class="nav-dropdown">
                <button class="nav-dropdown-btn" onclick="toggleNavDropdown(event, 'deliveriesDropdown')" title="Shipments & delivery scheduling">
                    <i class="fas fa-truck"></i> Deliveries <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 4L5 7L8 4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
                <div class="nav-dropdown-content" id="deliveriesDropdown">
                    <?php if ($isAdmin): ?>
                    <a href="create_shipment.php?project_id=<?php echo $project_id; ?>">Create Shipments</a>
                    <a href="manage_deliveries.php?project_id=<?php echo $project_id; ?>">Manage Deliveries</a>
                    <a href="scheduling.php?project_id=<?php echo $project_id; ?>">Scheduling</a>
                    <?php else: ?>
                    <a href="<?php echo $deliveriesLink; ?>">Delivery Schedule</a>
                    <?php endif; ?>
                    <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>">Anticipated Schedule</a>
                </div>
            </div>

            <!-- Warehousing Dropdown -->
            <div class="nav-dropdown">
                <button class="nav-dropdown-btn" onclick="toggleNavDropdown(event, 'warehousingDropdown')" title="Inventory & customs workflows">
                    <i class="fas fa-warehouse"></i> Warehousing <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 4L5 7L8 4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
                <div class="nav-dropdown-content" id="warehousingDropdown">
                    <?php if (!empty($warehouses_with_inventory)): ?>
                        <?php foreach ($warehouses_with_inventory as $wh): ?>
                        <a href="warehouse_info.php?warehouse_id=<?php echo $wh['id']; ?>&project_id=<?php echo $project_id; ?>">
                            <?php echo htmlspecialchars($wh['name']); ?>
                            <?php if ($wh['modules_in_warehouse'] > 0 || $wh['modules_in_transit_to_wh'] > 0): ?>
                            <span style="font-size: 0.75rem; color: #6c757d; margin-left: 4px;">(<?php echo number_format($wh['modules_in_warehouse'] + $wh['modules_in_transit_to_wh']); ?>)</span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="nav-dropdown-empty">No inventory in storage</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reports Dropdown (All Roles) -->
            <div class="nav-dropdown">
                <button class="nav-dropdown-btn" onclick="toggleNavDropdown(event, 'reportsDropdown')" title="Cost, sustainability & exports">
                    <i class="fas fa-chart-bar"></i> Reports <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 4L5 7L8 4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
                <div class="nav-dropdown-content" id="reportsDropdown">
                    <a href="project_cost_details?project_id=<?php echo $project_id; ?>">Costs</a>
                    <a href="project_sustainability_details?project_id=<?php echo $project_id; ?>">Sustainability</a>
                    <a href="manufacturer_overview">Manufacturers</a>
                    <a href="warranty.php?project_id=<?php echo $project_id; ?>">Exceptions</a>
                    <a href="project_close.php?project_id=<?php echo $project_id; ?>">Export Data</a>
                </div>
            </div>

            <!-- Documents Dropdown -->
            <div class="nav-dropdown">
                <button class="nav-dropdown-btn" onclick="toggleNavDropdown(event, 'documentsDropdown')" title="Project & global document libraries">
                    <i class="fas fa-folder-open"></i> Documents <svg width="10" height="10" viewBox="0 0 10 10"><path d="M2 4L5 7L8 4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                </button>
                <div class="nav-dropdown-content" id="documentsDropdown">
                    <a href="project_documents?project_id=<?php echo $project_id; ?>">Project Documents</a>
                    <a href="global_documents.php?project_id=<?php echo $project_id; ?>&from=project_overview">Global Documents</a>
                </div>
            </div>
        </div>
    </div>

    <?php include 'components/project_overview/views_unified.php'; ?>

    <?php if ($can_add_modules): ?>
    <!-- Health Status Modal -->
    <div class="health-modal-overlay" id="healthModal">
        <div class="health-modal">
            <div class="health-modal-header">
                <h3>Update Project Health Status</h3>
                <button type="button" class="health-modal-close" onclick="closeHealthModal()">&times;</button>
            </div>
            <form method="POST" action="project_overview.php?project_id=<?php echo $project_id; ?>" id="healthForm">
                <input type="hidden" name="action" value="update_project_health">
                <div class="health-modal-body">
                    <p style="color: #6c757d; font-size: 0.9rem; margin-bottom: 16px;">
                        <?php if ($is_manual_health): ?>
                        Current status is <strong>manually set</strong>. You can change the status or revert to auto-calculated.
                        <?php else: ?>
                        Current status is <strong>auto-calculated</strong> based on project timeline and delivery progress.
                        <?php endif; ?>
                    </p>

                    <label class="health-option<?php echo (!$is_manual_health) ? ' selected' : ''; ?>" onclick="selectHealthOption('auto')">
                        <input type="radio" name="health_status" value="auto" <?php echo (!$is_manual_health) ? 'checked' : ''; ?>>
                        <div class="health-option-content">
                            <div class="health-option-label">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                                Auto-Calculate
                            </div>
                            <div class="health-option-desc">Let the system determine status based on delivery progress and timeline</div>
                        </div>
                    </label>

                    <label class="health-option<?php echo ($is_manual_health && $project_health === 'on_track') ? ' selected' : ''; ?>" onclick="selectHealthOption('on_track')">
                        <input type="radio" name="health_status" value="on_track" <?php echo ($is_manual_health && $project_health === 'on_track') ? 'checked' : ''; ?>>
                        <div class="health-option-content">
                            <div class="health-option-label">
                                <span class="health-dot" style="background: #28a745;"></span>
                                On Track
                            </div>
                            <div class="health-option-desc">Project is progressing as expected</div>
                        </div>
                    </label>

                    <label class="health-option<?php echo ($is_manual_health && $project_health === 'at_risk') ? ' selected' : ''; ?>" onclick="selectHealthOption('at_risk')">
                        <input type="radio" name="health_status" value="at_risk" <?php echo ($is_manual_health && $project_health === 'at_risk') ? 'checked' : ''; ?>>
                        <div class="health-option-content">
                            <div class="health-option-label">
                                <span class="health-dot" style="background: #ffc107;"></span>
                                At Risk
                            </div>
                            <div class="health-option-desc">Project may encounter delays or issues</div>
                        </div>
                    </label>

                    <label class="health-option<?php echo ($is_manual_health && $project_health === 'behind') ? ' selected' : ''; ?>" onclick="selectHealthOption('behind')">
                        <input type="radio" name="health_status" value="behind" <?php echo ($is_manual_health && $project_health === 'behind') ? 'checked' : ''; ?>>
                        <div class="health-option-content">
                            <div class="health-option-label">
                                <span class="health-dot" style="background: #dc3545;"></span>
                                Behind Schedule
                            </div>
                            <div class="health-option-desc">Project is behind target timeline</div>
                        </div>
                    </label>

                    <div class="health-reason-container" id="healthReasonContainer">
                        <label for="healthReason">Reason for status change <span style="color: #dc3545;">*</span></label>
                        <textarea name="health_reason" id="healthReason" placeholder="Please explain why you're setting this status..." maxlength="500"><?php echo $is_manual_health ? htmlspecialchars($project_health_reason) : ''; ?></textarea>
                        <div class="char-count"><span id="charCount">0</span>/500</div>
                    </div>
                </div>
                <div class="health-modal-footer">
                    <button type="button" class="health-modal-btn cancel" onclick="closeHealthModal()">Cancel</button>
                    <button type="submit" class="health-modal-btn save" id="healthSaveBtn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Order Breakdown Modal -->
    <div class="dc-modal-overlay" id="orderBreakdownModal">
        <div class="dc-modal">
            <div class="dc-modal-header">
                <div>
                    <h3>Modules Ordered</h3>
                    <p class="dc-modal-subtitle">Modules assigned to this project vs project target</p>
                </div>
                <button type="button" class="dc-modal-close" onclick="closeOrderBreakdownModal()">&times;</button>
            </div>
            <div class="dc-modal-body">
                <!-- Ordered vs Target comparison -->
                <div class="om-comparison">
                    <div class="om-compare-box om-ordered">
                        <span class="om-compare-num"><?php echo number_format($ordered_mw, 2); ?></span>
                        <span class="om-compare-unit">MW</span>
                        <span class="om-compare-label">Ordered</span>
                    </div>
                    <div class="om-compare-vs">vs</div>
                    <div class="om-compare-box om-target">
                        <span class="om-compare-num"><?php echo number_format($project_size_mw, 2); ?></span>
                        <span class="om-compare-unit">MW</span>
                        <span class="om-compare-label">Project Size</span>
                    </div>
                </div>

                <!-- Progress bar -->
                <div class="om-progress">
                    <div class="om-progress-bar-wrap">
                        <div class="om-progress-bar" style="width: <?php echo min(100, $order_progress_pct); ?>%"></div>
                    </div>
                    <div class="om-progress-meta">
                        <span class="om-progress-pct"><?php echo number_format($order_progress_pct, 0); ?>% filled</span>
                        <?php if ($remaining_mw > 0): ?>
                        <span class="om-progress-status om-status-warning"><?php echo number_format($remaining_mw, 2); ?> MW remaining</span>
                        <?php elseif ($remaining_mw < 0): ?>
                        <span class="om-progress-status om-status-success">+<?php echo number_format(abs($remaining_mw), 2); ?> MW over target</span>
                        <?php else: ?>
                        <span class="om-progress-status om-status-success">Target reached</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($total_orders)): ?>
                <!-- Module breakdown table -->
                <div class="om-breakdown">
                    <div class="om-breakdown-title"><?php echo number_format($total_raw_modules); ?> modules across <?php echo count($total_orders); ?> wattage <?php echo count($total_orders) === 1 ? 'type' : 'types'; ?></div>
                    <div class="om-breakdown-table">
                        <div class="om-table-header">
                            <span>Wattage</span>
                            <span>Modules</span>
                            <span class="om-col-right">MW</span>
                            <span class="om-col-right">Share</span>
                        </div>
                        <?php foreach ($total_orders as $label => $info):
                            $wattMw = ($info['raw_quantity'] * $info['wattage']) / 1000000;
                            $wattPct = $ordered_mw > 0 ? ($wattMw / $ordered_mw) * 100 : 0;
                        ?>
                        <div class="om-table-row">
                            <span class="om-row-wattage"><?php echo $label; ?></span>
                            <span class="om-row-modules"><?php echo number_format($info['raw_quantity']); ?></span>
                            <span class="om-row-mw om-col-right"><?php echo number_format($wattMw, 3); ?></span>
                            <span class="om-row-share om-col-right"><?php echo number_format($wattPct, 1); ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($can_add_modules): ?>
                <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="order-modal-add-btn">+ Add Modules</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($dc_overall_pct !== null): ?>
    <!-- Domestic Content Modal -->
    <div class="dc-modal-overlay" id="dcModal">
        <div class="dc-modal">
            <div class="dc-modal-header">
                <div>
                    <h3>Domestic Content</h3>
                    <p class="dc-modal-subtitle"><?php echo $dc_tracked_coverage; ?>% of total watts tracked</p>
                </div>
                <button type="button" class="dc-modal-close" onclick="closeDomesticContentModal()">&times;</button>
            </div>
            <div class="dc-modal-body">
                <div class="dc-overall-stat">
                    <span class="dc-overall-number"><?php echo number_format($dc_overall_pct, 1); ?>%</span>
                    <span class="dc-overall-label">Weighted Average</span>
                </div>

                <?php foreach ($dc_by_batch as $bid => $bdc): ?>
                <div class="dc-batch-card">
                    <div class="dc-batch-header">
                        <span class="dc-batch-name"><?php echo htmlspecialchars($bdc['vendor_name']); ?></span>
                        <?php if ($bdc['dc_pct'] !== null): ?>
                        <span class="dc-batch-pct"><?php echo number_format($bdc['dc_pct'], 1); ?>%</span>
                        <?php else: ?>
                        <span class="dc-batch-pct dc-na">N/A</span>
                        <?php endif; ?>
                    </div>
                    <div class="dc-wattage-rows">
                        <?php foreach ($bdc['wattages'] as $winfo): ?>
                        <div class="dc-wattage-row">
                            <span class="dc-wattage-label"><?php echo number_format($winfo['wattage'], 0); ?>W</span>
                            <span class="dc-wattage-modules"><?php echo number_format($winfo['modules']); ?> modules</span>
                            <?php if ($winfo['dc_pct'] !== null): ?>
                            <div class="dc-wattage-bar-wrap">
                                <div class="dc-wattage-bar" style="width: <?php echo min(100, $winfo['dc_pct']); ?>%"></div>
                            </div>
                            <span class="dc-wattage-pct"><?php echo number_format($winfo['dc_pct'], 1); ?>%</span>
                            <?php else: ?>
                            <span class="dc-wattage-pct dc-na">Not tracked</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
// ==================== NAV DROPDOWN TOGGLE ====================
function toggleNavDropdown(event, dropdownId) {
    event.stopPropagation();
    const dropdown = document.getElementById(dropdownId);
    const allDropdowns = document.querySelectorAll('.nav-dropdown-content');
    const allNavDropdowns = document.querySelectorAll('.nav-dropdown');

    // Close all other dropdowns
    allDropdowns.forEach(d => {
        if (d.id !== dropdownId) {
            d.classList.remove('show');
        }
    });
    allNavDropdowns.forEach(nd => {
        if (!nd.contains(dropdown)) {
            nd.classList.remove('open');
        }
    });

    // Toggle the clicked dropdown
    const isOpen = dropdown.classList.contains('show');
    dropdown.classList.toggle('show', !isOpen);
    dropdown.closest('.nav-dropdown').classList.toggle('open', !isOpen);
}

// Close nav dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.nav-dropdown')) {
        document.querySelectorAll('.nav-dropdown-content').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.nav-dropdown').forEach(nd => nd.classList.remove('open'));
    }
});

// ==================== TAB NAVIGATION ====================

function activateTab(tabId) {
    if (tabId === 'tab-financial') {
        window.location.href = 'project_cost_details.php?project_id=<?php echo $project_id; ?>';
        return;
    }

    // Update tab button states
    document.querySelectorAll('.project-tabs .tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });

    // Show/hide tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        const isTarget = content.id === tabId;
        content.style.display = isTarget ? 'block' : 'none';
        content.classList.toggle('active', isTarget);
    });

    // Initialize charts when needed
    if (tabId === 'tab-deliveries') {
        initializeDeliveryCharts();
    }

    history.replaceState(null, null, '#' + tabId);
}

// Hash-based tab routing
function setActiveTabFromHash() {
    const hash = window.location.hash.replace('#', '');
    if (hash === 'tab-financial') {
        window.location.replace('project_cost_details.php?project_id=<?php echo $project_id; ?>');
        return;
    }
    if (hash && document.getElementById(hash)) {
        activateTab(hash);
    }
}

// Initialize tab click listeners
document.querySelectorAll('.project-tabs .tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        activateTab(this.dataset.tab);
    });
});

// Defer tab activation until DOM and scripts are fully ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setActiveTabFromHash);
} else {
    setActiveTabFromHash();
}
window.addEventListener('hashchange', setActiveTabFromHash);

function toggleDropdown(dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;
    const isOpen = dropdown.classList.contains('show');
    document.querySelectorAll('.dropdown-content').forEach(d => {
        d.classList.remove('show');
        d.style.display = ''; // Clear inline style so CSS class takes precedence
    });
    if (!isOpen) {
        dropdown.classList.add('show');
        dropdown.style.display = ''; // Clear any inline display:none
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.matches('.dropdown-btn') && !e.target.closest('.dropdown-btn')) {
        document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
    }
});

// Unit filter sync - works with both old .unit-filter-btn and new .filter-chip
function syncUnitFilters(unit) {
    // Update old unit filter buttons
    document.querySelectorAll('.unit-filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.unit === unit);
    });
    // Update new filter chips
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.unit === unit);
    });
    updateShippingBoxes(unit);
    updateAnalyticsTables(unit);
}

document.addEventListener('DOMContentLoaded', function() {
    // Old unit filter buttons
    document.querySelectorAll('.unit-filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            syncUnitFilters(this.dataset.unit);
        });
    });
    // New filter chips
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            syncUnitFilters(this.dataset.unit);
        });
    });
});

function updateShippingBoxes(unit) {
    document.querySelectorAll('.shipping-box, .shipping-box-customer').forEach(box => {
        const countEl = box.querySelector('.status-count');
        const unitEl = box.querySelector('.status-unit');
        if (!countEl || !unitEl) return;
        let value, unitLabel;
        switch(unit) {
            case 'modules': value = parseInt(box.dataset.modules || 0); unitLabel = 'modules'; break;
            case 'pallets': value = parseInt(box.dataset.pallets || 0); unitLabel = 'pallets'; break;
            case 'truckloads': value = parseFloat(box.dataset.truckloads || 0); unitLabel = 'truckloads'; break;
            default: value = parseFloat(box.dataset.mws || 0); unitLabel = 'MWs';
        }
        countEl.textContent = (unit === 'mws') ? value.toFixed(2) : (unit === 'truckloads') ? value.toFixed(1) : Math.round(value).toLocaleString();
        unitEl.textContent = unitLabel;
    });
}

// Analytics tables data and conversion factors
var analyticsTableData = <?php echo json_encode([
    'sub_rows' => $sub_rows ?? [],
    'sub_rows_status' => $sub_rows_status ?? [],
    'combined' => [
        'total_order' => $total_order_combined ?? 0,
        'delivered' => $delivered_combined ?? 0,
        'at_manufacturer' => $at_manufacturer_combined ?? 0,
        'on_water' => $on_water_combined ?? 0,
        'customs_hold' => $customs_hold_combined ?? 0,
        'cleared_customs' => $cleared_customs_combined ?? 0,
        'in_transit_to_warehouse' => $in_transit_to_warehouse_combined ?? 0,
        'in_warehouse' => $in_warehouse_combined ?? 0,
        'in_transit_to_project' => $in_transit_to_project_combined ?? 0,
        'anticipated_quantities' => $anticipated_quantities_combined ?? []
    ],
    'module_type_combined' => $module_type_combined ?? 'N/A'
], JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;

var conversionFactors = {
    avgWattage: <?php echo $avg_wattage ?? 0; ?>,
    modulesPerPallet: <?php echo $average_modules_per_pallet ?? 30; ?>,
    palletsPerTruck: <?php echo $average_pallets_per_truck ?? 24; ?>
};

function convertValue(mwValue, unit, wattage) {
    if (!mwValue || mwValue === 0) return 0;
    var w = wattage || conversionFactors.avgWattage || 1;
    if (w === 0) w = 1;

    // MW value is already in MW, convert to modules first
    var modules = (mwValue * 1000000) / w;

    switch(unit) {
        case 'modules': return Math.round(modules);
        case 'pallets': return Math.round(modules / conversionFactors.modulesPerPallet);
        case 'truckloads':
            var pallets = modules / conversionFactors.modulesPerPallet;
            return pallets / conversionFactors.palletsPerTruck;
        default: return mwValue; // MWs
    }
}

function formatValue(value, unit) {
    if (unit === 'mws') return value.toFixed(2);
    if (unit === 'truckloads') return value.toFixed(1);
    return Math.round(value).toLocaleString();
}

function updateAnalyticsTables(unit) {
    var table1 = document.getElementById('table1');
    var table2 = document.getElementById('table2');
    if (!table1 && !table2) return;

    var data = analyticsTableData;
    if (!data || !data.combined) return;

    var subRowKeys = Object.keys(data.sub_rows);
    var subRowStatusKeys = Object.keys(data.sub_rows_status);

    // Calculate combined values by summing converted sub-row values (more accurate than converting combined MW)
    function calcCombinedFromSubRows(subRows, keys, field) {
        var total = 0;
        keys.forEach(function(key) {
            var sr = subRows[key];
            var wattage = parseInt(sr.wattage_label) || conversionFactors.avgWattage;
            var value = sr[field] || 0;
            total += convertValue(value, unit, wattage);
        });
        return total;
    }

    // Update Table 1 (Next 5 Weeks of Deliveries)
    if (table1) {
        var tbody1 = table1.querySelector('tbody');
        if (tbody1) {
            var allRows = tbody1.querySelectorAll('tr');

            // Calculate combined values from sub-rows
            var combinedTotalOrder = calcCombinedFromSubRows(data.sub_rows, subRowKeys, 'total_order');
            var combinedDelivered = calcCombinedFromSubRows(data.sub_rows, subRowKeys, 'delivered');

            // Calculate combined anticipated quantities for each week
            var numWeeks = (data.combined.anticipated_quantities || []).length;
            var combinedAnticipated = [];
            for (var w = 0; w < numWeeks; w++) {
                var weekTotal = 0;
                subRowKeys.forEach(function(key) {
                    var sr = data.sub_rows[key];
                    var wattage = parseInt(sr.wattage_label) || conversionFactors.avgWattage;
                    var value = (sr.anticipated_quantities && sr.anticipated_quantities[w]) || 0;
                    weekTotal += convertValue(value, unit, wattage);
                });
                combinedAnticipated.push(weekTotal);
            }

            // Main combined row (first row - not a sub-row)
            if (allRows[0] && !allRows[0].classList.contains('delivery-row')) {
                var cells = allRows[0].querySelectorAll('td');
                if (cells.length >= 3) {
                    cells[1].textContent = formatValue(combinedTotalOrder, unit);
                    cells[2].textContent = formatValue(combinedDelivered, unit);
                    // Week columns
                    for (var i = 0; i < combinedAnticipated.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatValue(combinedAnticipated[i], unit);
                    }
                }
            }

            // Get all sub-rows with class 'delivery-row'
            var deliverySubRows = tbody1.querySelectorAll('tr.delivery-row');

            deliverySubRows.forEach(function(subRow, index) {
                if (index < subRowKeys.length) {
                    var sr = data.sub_rows[subRowKeys[index]];
                    var cells = subRow.querySelectorAll('td');
                    var wattage = parseInt(sr.wattage_label) || conversionFactors.avgWattage;
                    if (cells.length >= 3) {
                        cells[1].textContent = formatValue(convertValue(sr.total_order, unit, wattage), unit);
                        cells[2].textContent = formatValue(convertValue(sr.delivered, unit, wattage), unit);
                        for (var i = 0; i < (sr.anticipated_quantities || []).length && i + 3 < cells.length; i++) {
                            cells[i + 3].textContent = formatValue(convertValue(sr.anticipated_quantities[i], unit, wattage), unit);
                        }
                    }
                }
            });
        }
    }

    // Update Table 2 (Module Delivery Status)
    if (table2) {
        var tbody2 = table2.querySelector('tbody');
        if (tbody2) {
            var allRows = tbody2.querySelectorAll('tr');

            // Calculate combined values from sub-rows for status table
            var combinedStatusTotalOrder = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'total_order');
            var combinedStatusAtMfr = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'at_manufacturer');
            var combinedStatusOnWater = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'on_water');
            var combinedStatusCustomsHold = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'customs_hold');
            var combinedStatusCleared = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'cleared_customs');
            var combinedStatusToWarehouse = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'in_transit_to_warehouse');
            var combinedStatusInWarehouse = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'in_warehouse');
            var combinedStatusToProject = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'in_transit_to_project');
            var combinedStatusDelivered = calcCombinedFromSubRows(data.sub_rows_status, subRowStatusKeys, 'delivered');

            // Main combined row (first row - not a sub-row)
            if (allRows[0] && !allRows[0].classList.contains('status-row')) {
                var cells = allRows[0].querySelectorAll('td');
                var cellIndex = 1;
                if (cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusTotalOrder, unit);
                if (cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusAtMfr, unit);
                if (data.combined.on_water > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusOnWater, unit);
                if (data.combined.customs_hold > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusCustomsHold, unit);
                if (data.combined.cleared_customs > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusCleared, unit);
                if (data.combined.in_transit_to_warehouse > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusToWarehouse, unit);
                if (data.combined.in_warehouse > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusInWarehouse, unit);
                if (data.combined.in_transit_to_project > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(combinedStatusToProject, unit);
                if (cells[cellIndex]) cells[cellIndex].textContent = formatValue(combinedStatusDelivered, unit);
            }

            // Get all sub-rows with class 'status-row'
            var statusSubRows = tbody2.querySelectorAll('tr.status-row');

            statusSubRows.forEach(function(subRow, index) {
                if (index < subRowStatusKeys.length) {
                    var srs = data.sub_rows_status[subRowStatusKeys[index]];
                    var cells = subRow.querySelectorAll('td');
                    var wattage = parseInt(srs.wattage_label) || conversionFactors.avgWattage;
                    var cellIndex = 1;
                    if (cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.total_order, unit, wattage), unit);
                    if (cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.at_manufacturer || 0, unit, wattage), unit);
                    if (data.combined.on_water > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.on_water || 0, unit, wattage), unit);
                    if (data.combined.customs_hold > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.customs_hold || 0, unit, wattage), unit);
                    if (data.combined.cleared_customs > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.cleared_customs || 0, unit, wattage), unit);
                    if (data.combined.in_transit_to_warehouse > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.in_transit_to_warehouse || 0, unit, wattage), unit);
                    if (data.combined.in_warehouse > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.in_warehouse || 0, unit, wattage), unit);
                    if (data.combined.in_transit_to_project > 0 && cells[cellIndex]) cells[cellIndex++].textContent = formatValue(convertValue(srs.in_transit_to_project || 0, unit, wattage), unit);
                    if (cells[cellIndex]) cells[cellIndex].textContent = formatValue(convertValue(srs.delivered, unit, wattage), unit);
                }
            });
        }
    }
}

// Chart initialization flags
let chartsInitialized = { delivery: false, financial: false };

function initializeDeliveryCharts() {
    if (chartsInitialized.delivery) return;
    try {
        initLineChart();
        initPieChart();
        chartsInitialized.delivery = true;
    } catch(e) {
        // Chart.js may not be loaded yet — will retry on next tab activation
        console.warn('Delivery charts not ready, will retry:', e.message);
    }
}

function initializeFinancialCharts() {
    if (chartsInitialized.financial) return;
    try {
        initBudgetLineChart();
        initCostPieChart();
        chartsInitialized.financial = true;
    } catch(e) {
        console.warn('Financial charts not ready, will retry:', e.message);
    }
}

// Legacy compatibility
function showView(viewId) {
    if (viewId === 'financial-info') {
        window.location.href = 'project_cost_details.php?project_id=<?php echo $project_id; ?>';
        return;
    }

    const viewMap = {
        'progress-info': 'tab-timeline',
        'project-progress': 'tab-timeline',
        'site-info': 'tab-site',
        'module-info': 'tab-modules',
        'delivery-info': 'tab-deliveries'
    };
    const tabId = viewMap[viewId];
    if (tabId) activateTab(tabId);
}

function toggleModulesDropdown() { toggleDropdown('modulesDropdown'); }
function toggleAdminDeliveriesDropdown() { toggleDropdown('deliveriesDropdown'); }
function toggleCustomerDeliveriesDropdown() { toggleDropdown('deliveriesDropdown'); }
function toggleAdminDocumentsDropdown() { toggleDropdown('documentsDropdown'); }
function toggleCustomerDocumentsDropdown() { toggleDropdown('documentsDropdown'); }
function toggleCustomerModulesDropdown() { toggleDropdown('modulesDropdown'); }
function toggleCustomerReportsDropdown() { toggleDropdown('reportsDropdown'); }

// Toggle sub-rows in tables (for wattage breakdowns)
function toggleSubRows(className) {
    const rows = document.querySelectorAll('.' + className);
    rows.forEach(row => {
        row.style.display = row.style.display === 'none' || row.style.display === '' ? 'table-row' : 'none';
    });
}

// Close shipping modal
function closeShippingModal() {
    const modal = document.getElementById('shippingModal');
    if (modal) modal.style.display = 'none';
}

// Shipping modals
function showCustomerShippingModal(status, onlyKey) {
    const modal = document.getElementById('customerShippingModal');
    const title = document.getElementById('customerShippingModalTitle');
    const content = document.getElementById('customerShippingModalContent');
    if (!modal || !title || !content) return;

    const displayLabel = onlyKey ? onlyKey : status;
    title.textContent = displayLabel;

    let body = '';
    if (onlyKey && typeof shippingBreakdown !== 'undefined' && shippingBreakdown[onlyKey]) {
        const data = shippingBreakdown[onlyKey];
        body += '<div style="padding:18px;">';
        body += '<div style="margin-bottom:14px;font-weight:600;color:#293E4C;">' + displayLabel + '</div>';
        body += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';
        body += '<div style="flex:1;min-width:110px;text-align:center;padding:10px;background:#f8fafc;border-radius:8px;"><div style="font-size:1.4rem;font-weight:700;color:#488C9A;">' + Number(data.pallet_count || 0).toLocaleString() + '</div><div style="font-size:0.8rem;color:#6c757d;">Pallets</div></div>';
        body += '<div style="flex:1;min-width:110px;text-align:center;padding:10px;background:#f8fafc;border-radius:8px;"><div style="font-size:1.4rem;font-weight:700;color:#488C9A;">' + Number(data.total_modules || 0).toLocaleString() + '</div><div style="font-size:0.8rem;color:#6c757d;">Modules</div></div>';
        body += '</div>';
        if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
            body += '<div style="margin-top:10px;"><strong style="font-size:0.9rem;color:#293E4C;">Wattage Breakdown</strong><ul style="list-style:none;padding:0;margin-top:8px;">';
            for (const w in data.wattage_breakdown) {
                const d = data.wattage_breakdown[w];
                body += '<li style="padding:6px 0;border-bottom:1px solid #eee;font-size:0.9rem;">' + w + 'W: ' + d.pallets + ' pallets · ' + Number(d.modules || 0).toLocaleString() + ' modules</li>';
            }
            body += '</ul></div>';
        }
        body += '</div>';
    } else {
        body = '<div style="padding:20px;text-align:center;"><p>Status: <strong>' + displayLabel + '</strong></p></div>';
    }
    content.innerHTML = body;
    modal.style.display = 'block';
}

function closeCustomerShippingModal() {
    const modal = document.getElementById('customerShippingModal');
    if (modal) modal.style.display = 'none';
}

function closeConversionModal() {
    const modal = document.getElementById('conversionModal');
    if (modal) modal.style.display = 'none';
}

// Chart data and functions
var dateLabels = <?php echo $dateLabelsJSON; ?>;
var lineData = <?php echo $lineChartDataJSON; ?>;
var pieChartData = <?php echo json_encode(array_values($pieChartPercentages ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;
var pieChartLabels = <?php echo json_encode(array_keys($pieChartPercentages ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;
var colorMap = {
    'Delivered to Project': '#488C9A', 'At Manufacturer': '#293E4C', 'On Water': '#66B2FF',
    'Customs Hold': '#dc2626',
    'Cleared Customs': '#32CD32', 'In Transit to Warehouse': '#9370DB',
    'In Transit to Project': '#C0C0C0', 'In Warehouse': '#FF6B6B', 'Exceptions': '#f57c00'
};

function initLineChart() {
    var ctxLineEl = document.getElementById('lineChart');
    if (!ctxLineEl) return;
    new Chart(ctxLineEl.getContext('2d'), {
        type: 'line',
        data: {
            labels: dateLabels,
            datasets: [
                { label: 'Anticipated', data: lineData.anticipated, borderColor: '#488C9A', borderDash: [5,5], borderWidth: 2, fill: false, pointRadius: 0 },
                { label: 'Actual', data: lineData.actual, borderColor: '#293E4C', borderWidth: 2, fill: false, pointRadius: 0, spanGaps: false }
            ]
        },
        options: {
            responsive: true, animation: false,
            scales: {
                x: { type: 'time', time: { parser: 'yyyy-MM-dd', unit: 'week', displayFormats: { week: 'MMM d' } } },
                y: { beginAtZero: true, title: { display: true, text: '<?php echo ($view_mode=="mw") ? "MWs" : "Modules";?>' } }
            }
        }
    });
}

function initPieChart() {
    var ctxPieEl = document.getElementById('pieChart');
    if (!ctxPieEl) return;
    new Chart(ctxPieEl.getContext('2d'), {
        type: 'pie',
        data: {
            labels: pieChartLabels,
            datasets: [{ data: pieChartData, backgroundColor: pieChartLabels.map(l => colorMap[l] || '#ccc') }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
}

<?php if (!empty($enable_overview_financial)): ?>
// Financial chart data from PHP
var budgetChartData = <?php echo $budgetLineChartDataJSON ?? '{"anticipated_cost":[],"actual_cost":[]}'; ?>;
var budgetDateLabels = <?php echo $dateLabelsForBudget ?: '[]'; ?>;
var costPieData = <?php echo json_encode(array_values($pieChartDataFinancial ?? []), JSON_UNESCAPED_UNICODE) ?: '[0,0,0]'; ?>;
var costPieLabels = <?php echo json_encode(array_keys($pieChartDataFinancial ?? []), JSON_UNESCAPED_UNICODE) ?: '["Freight","Warehousing","Accessorial"]'; ?>;
var totalActualCost = <?php echo $total_logistics_cost ?? 0; ?>;
var totalForecastedCost = <?php echo ($forecasted_freight + $forecasted_warehousing + $forecasted_accessorial) ?? 0; ?>;

function initBudgetLineChart() {
    var el = document.getElementById('budgetLineChart');
    if (!el) return;
    new Chart(el.getContext('2d'), {
        type: 'line',
        data: {
            labels: budgetDateLabels,
            datasets: [
                {
                    label: 'Forecasted',
                    data: budgetChartData.anticipated_cost,
                    borderColor: '#488C9A',
                    borderDash: [5, 5],
                    borderWidth: 2,
                    fill: false,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#488C9A',
                    tension: 0.1
                },
                {
                    label: 'Actual',
                    data: budgetChartData.actual_cost,
                    borderColor: '#293E4C',
                    borderWidth: 2,
                    fill: false,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#293E4C',
                    spanGaps: false,
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    backgroundColor: 'rgba(41, 62, 76, 0.95)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: '#488C9A',
                    borderWidth: 1,
                    padding: 14,
                    displayColors: true,
                    callbacks: {
                        title: function(tooltipItems) {
                            if (tooltipItems.length > 0) {
                                var date = new Date(tooltipItems[0].parsed.x);
                                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                            }
                            return '';
                        },
                        label: function(context) {
                            return context.dataset.label + ': $' + (context.parsed.y || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        },
                        afterBody: function(tooltipItems) {
                            if (tooltipItems.length >= 2) {
                                var forecasted = tooltipItems[0].parsed.y || 0;
                                var actual = tooltipItems[1].parsed.y;
                                if (actual !== null && actual !== undefined) {
                                    var variance = actual - forecasted;
                                    var variancePercent = forecasted > 0 ? (variance / forecasted * 100) : 0;
                                    var sign = variance >= 0 ? '+' : '';
                                    return [
                                        '',
                                        'Variance: ' + sign + '$' + Number(Math.abs(variance)).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) +
                                        ' (' + sign + variancePercent.toFixed(1) + '%)'
                                    ];
                                }
                            }
                            return [];
                        },
                        footer: function() {
                            return 'Click for details';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: { parser: 'yyyy-MM-dd', unit: 'month', displayFormats: { month: 'MMM yyyy' } },
                    title: { display: true, text: 'Date' }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cost ($)' },
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            },
            onClick: function(event, elements) {
                if (elements.length > 0) {
                    var dataIndex = elements[0].index;
                    var date = budgetDateLabels[dataIndex];
                    var forecasted = budgetChartData.anticipated_cost[dataIndex] || 0;
                    var actual = budgetChartData.actual_cost[dataIndex];

                    // Get cumulative values at this point (already cumulative in the data)
                    var forecastedCumulative = budgetChartData.anticipated_cost[dataIndex] || 0;
                    var actualCumulative = budgetChartData.actual_cost[dataIndex] || 0;

                    // Get breakdown data for this point
                    var forecastBreakdown = budgetChartData.forecast_breakdown ? budgetChartData.forecast_breakdown[dataIndex] : null;
                    var actualBreakdown = budgetChartData.actual_breakdown ? budgetChartData.actual_breakdown[dataIndex] : null;

                    if (typeof openCostDetailModal === 'function') {
                        openCostDetailModal(date, forecasted, actual, forecastedCumulative, actualCumulative, forecastBreakdown, actualBreakdown);
                    }
                }
            },
            onHover: function(event, elements) {
                event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
            }
        }
    });
}

function initCostPieChart() {
    var el = document.getElementById('costPieChart');
    if (!el) return;

    // Check if there's any data
    var hasData = costPieData.some(v => v > 0);

    new Chart(el.getContext('2d'), {
        type: 'pie',
        data: {
            labels: costPieLabels,
            datasets: [{
                data: hasData ? costPieData : [1, 1, 1], // Show equal slices if no data
                backgroundColor: ['#488C9A', '#293E4C', '#fbb040', '#5ba3b1', '#6c757d']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (!hasData) return context.label + ': No data yet';
                            var value = context.parsed || 0;
                            var total = context.dataset.data.reduce((a, b) => a + b, 0);
                            var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return context.label + ': $' + value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

function showView(viewId) {
    if (viewId === 'financial-info') {
        window.location.href = 'project_cost_details.php?project_id=<?php echo $project_id; ?>';
        return;
    }

    const viewMap = {
        'progress-info': 'tab-timeline',
        'project-progress': 'tab-timeline',
        'site-info': 'tab-site',
        'module-info': 'tab-modules',
        'delivery-info': 'tab-deliveries'
    };
    const tabId = viewMap[viewId];
    if (tabId) activateTab(tabId);
}

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Site information data from PHP
const siteInfoData = {
    address: <?php echo json_encode(trim(($project['street_address'] ?? '') . ', ' . ($project['city'] ?? '') . ', ' . ($project['state'] ?? '') . ' ' . ($project['zip_code'] ?? ''), ', ')); ?>,
    streetAddress: <?php echo json_encode($project['street_address'] ?? ''); ?>,
    city: <?php echo json_encode($project['city'] ?? ''); ?>,
    state: <?php echo json_encode($project['state'] ?? ''); ?>,
    zipCode: <?php echo json_encode($project['zip_code'] ?? ''); ?>,
    phone1: <?php echo json_encode($project['phone1'] ?? ''); ?>,
    phone2: <?php echo json_encode($project['phone2'] ?? ''); ?>,
    timezone: <?php echo json_encode($project['timezone'] ?? 'America/New_York'); ?>,
    appointmentDuration: <?php echo json_encode($project['appointment_duration'] ?? 30); ?>,
    instructions: <?php echo json_encode($project['instructions'] ?? ''); ?>,
    additionalNotes: <?php echo json_encode($project['additional_notes'] ?? ''); ?>,
    siteContact: {
        name: <?php echo json_encode($project['site_contact_name'] ?? ''); ?>,
        email: <?php echo json_encode($project['site_contact_email'] ?? ''); ?>,
        phone: <?php echo json_encode($project['site_contact_phone'] ?? ''); ?>
    },
    operatingHours: <?php echo json_encode($site_operating_hours); ?>,
    projectId: <?php echo $project_id; ?>
};

const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const timezoneLabels = {
    'America/New_York': 'Eastern',
    'America/Chicago': 'Central',
    'America/Denver': 'Mountain',
    'America/Los_Angeles': 'Pacific',
    'UTC': 'UTC'
};

function formatTime12h(time24) {
    if (!time24) return '';
    const [hours, minutes] = time24.split(':');
    const h = parseInt(hours, 10);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${minutes} ${ampm}`;
}

function loadSiteInfo() {
    var siteSection = document.getElementById('site-info');
    if(siteSection && siteSection.innerHTML.trim() === '') {
        // Build operating hours HTML
        let hoursHtml = '';
        let hasHours = Object.keys(siteInfoData.operatingHours).length > 0;
        if (hasHours) {
            hoursHtml = '<div class="hours-grid-display">';
            for (let day = 0; day <= 6; day++) {
                const hours = siteInfoData.operatingHours[day];
                if (hours) {
                    hoursHtml += `<div class="hours-day-row">
                        <span class="day-name">${dayNames[day]}</span>
                        <span class="day-hours">${formatTime12h(hours.start)} - ${formatTime12h(hours.end)}</span>
                    </div>`;
                } else {
                    hoursHtml += `<div class="hours-day-row closed">
                        <span class="day-name">${dayNames[day]}</span>
                        <span class="day-hours">Closed</span>
                    </div>`;
                }
            }
            hoursHtml += '</div>';
        } else {
            hoursHtml = '<p style="color: #6c757d; font-style: italic;">No hours configured</p>';
        }

        // Build contact HTML
        let contactHtml = '';
        if (siteInfoData.siteContact.name || siteInfoData.siteContact.email || siteInfoData.siteContact.phone) {
            contactHtml = '<div class="contact-info">';
            if (siteInfoData.siteContact.name) contactHtml += `<div><i class="fas fa-user"></i> ${siteInfoData.siteContact.name}</div>`;
            if (siteInfoData.siteContact.email) contactHtml += `<div><i class="fas fa-envelope"></i> <a href="mailto:${siteInfoData.siteContact.email}">${siteInfoData.siteContact.email}</a></div>`;
            if (siteInfoData.siteContact.phone) contactHtml += `<div><i class="fas fa-phone"></i> <a href="tel:${siteInfoData.siteContact.phone}">${siteInfoData.siteContact.phone}</a></div>`;
            contactHtml += '</div>';
        } else {
            contactHtml = '<p style="color: #6c757d; font-style: italic;">No contact configured</p>';
        }

        siteSection.innerHTML = `
            <style>
                .site-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
                .site-info-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e9ecef; }
                .site-info-card h4 { margin: 0 0 16px 0; color: #293E4C; font-size: 1rem; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid #488C9A; padding-bottom: 8px; }
                .site-info-card h4 i { color: #488C9A; }
                .hours-grid-display { display: flex; flex-direction: column; gap: 4px; }
                .hours-day-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
                .hours-day-row:last-child { border-bottom: none; }
                .hours-day-row .day-name { font-weight: 500; color: #293E4C; }
                .hours-day-row .day-hours { color: #28a745; font-weight: 500; }
                .hours-day-row.closed .day-hours { color: #dc3545; }
                .contact-info { display: flex; flex-direction: column; gap: 10px; }
                .contact-info div { display: flex; align-items: center; gap: 10px; }
                .contact-info i { color: #488C9A; width: 16px; }
                .contact-info a { color: #488C9A; text-decoration: none; }
                .contact-info a:hover { text-decoration: underline; }
                .instructions-text { background: #f8f9fa; padding: 12px; border-radius: 8px; color: #495057; line-height: 1.6; white-space: pre-wrap; }
                .site-docs-container { margin-top: 24px; }
                .site-docs-list { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
                .site-doc-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 8px; transition: background 0.2s; }
                .site-doc-item:hover { background: #e9ecef; }
                .site-doc-item .doc-icon { font-size: 1.5rem; }
                .site-doc-item .doc-info { flex: 1; }
                .site-doc-item .doc-name { font-weight: 500; color: #293E4C; }
                .site-doc-item .doc-type { font-size: 0.85rem; color: #6c757d; }
                .site-doc-item .doc-actions a { color: #488C9A; text-decoration: none; padding: 6px 12px; border: 1px solid #488C9A; border-radius: 6px; font-size: 0.85rem; }
                .site-doc-item .doc-actions a:hover { background: #488C9A; color: #fff; }
                .site-info-meta { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 16px; }
                .site-info-meta-item { display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: #e8f4f7; border-radius: 6px; font-size: 0.9rem; }
                .site-info-meta-item i { color: #488C9A; }
            </style>

            <div class="site-info-meta">
                <div class="site-info-meta-item"><i class="fas fa-clock"></i> ${timezoneLabels[siteInfoData.timezone] || siteInfoData.timezone}</div>
                <div class="site-info-meta-item"><i class="fas fa-calendar-alt"></i> ${siteInfoData.appointmentDuration} min appointments</div>
                ${siteInfoData.phone1 ? `<div class="site-info-meta-item"><i class="fas fa-phone"></i> ${siteInfoData.phone1}</div>` : ''}
            </div>

            <div class="site-info-grid">
                <div class="site-info-card">
                    <h4><i class="fas fa-clock"></i> Receiving Hours</h4>
                    ${hoursHtml}
                </div>

                <div class="site-info-card">
                    <h4><i class="fas fa-user-tie"></i> Site Contact</h4>
                    ${contactHtml}
                </div>

                ${siteInfoData.instructions ? `
                <div class="site-info-card">
                    <h4><i class="fas fa-clipboard-list"></i> Special Instructions</h4>
                    <div class="instructions-text">${siteInfoData.instructions}</div>
                </div>
                ` : ''}

                ${siteInfoData.additionalNotes ? `
                <div class="site-info-card">
                    <h4><i class="fas fa-sticky-note"></i> Additional Notes</h4>
                    <div class="instructions-text">${siteInfoData.additionalNotes}</div>
                </div>
                ` : ''}
            </div>

            <div class="site-docs-container">
                <h4 style="margin: 0 0 4px 0; color: #293E4C;"><i class="fas fa-file-alt" style="color: #488C9A; margin-right: 8px;"></i>Site Documents</h4>
                <div id="site-docs-list" class="site-docs-list">
                    <div style="text-align: center; padding: 20px; color: #6c757d;">Loading documents...</div>
                </div>
            </div>
        `;

        // Fetch site documents
        loadSiteDocuments();
    }
}

function loadSiteDocuments() {
    const projectId = siteInfoData.projectId;
    // Fetch documents with type: site
    fetch(`get_project_documents.php?project_id=${projectId}&document_type_in=site`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('site-docs-list');
            if (!data.success || !data.documents || data.documents.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d; font-style: italic;">No site documents uploaded yet</div>';
                return;
            }

            let html = '';
            data.documents.forEach(doc => {
                const ext = (doc.original_name || '').split('.').pop().toLowerCase();
                let icon = '📄';
                if (['pdf'].includes(ext)) icon = '📕';
                else if (['doc', 'docx'].includes(ext)) icon = '📘';
                else if (['xls', 'xlsx'].includes(ext)) icon = '📗';
                else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) icon = '🖼️';

                html += `
                    <div class="site-doc-item">
                        <div class="doc-icon">${icon}</div>
                        <div class="doc-info">
                            <div class="doc-name">${doc.original_name || 'Document'}</div>
                            <div class="doc-type">${doc.document_sub_type || doc.document_type || 'Document'}</div>
                        </div>
                        <div class="doc-actions">
                            <a href="${doc.file_path}" target="_blank"><i class="fas fa-download"></i> View</a>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            document.getElementById('site-docs-list').innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading documents</div>';
        });
}

function loadModuleInfo() {
    var moduleSection = document.getElementById('module-info');
    if(moduleSection && moduleSection.innerHTML.trim() === '') {
        moduleSection.innerHTML = `
            <style>
                .module-info-container { }
                .module-batches-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 24px; }
                .module-batch-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e9ecef; }
                .module-batch-card h4 { margin: 0 0 12px 0; color: #293E4C; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
                .module-batch-card h4 .batch-badge { background: #488C9A; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; }
                .module-specs-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
                .spec-item { text-align: center; padding: 10px; background: #f8f9fa; border-radius: 8px; }
                .spec-item .spec-value { font-size: 1.2rem; font-weight: 700; color: #293E4C; }
                .spec-item .spec-label { font-size: 0.75rem; color: #6c757d; margin-top: 4px; }
                .module-docs-container { margin-top: 24px; }
                .module-docs-list { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
                .module-doc-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 8px; transition: background 0.2s; }
                .module-doc-item:hover { background: #e9ecef; }
                .module-doc-item .doc-icon { font-size: 1.5rem; }
                .module-doc-item .doc-info { flex: 1; }
                .module-doc-item .doc-name { font-weight: 500; color: #293E4C; }
                .module-doc-item .doc-type { font-size: 0.85rem; color: #6c757d; }
                .module-doc-item .doc-actions a { color: #6f42c1; text-decoration: none; padding: 6px 12px; border: 1px solid #6f42c1; border-radius: 6px; font-size: 0.85rem; }
                .module-doc-item .doc-actions a:hover { background: #6f42c1; color: #fff; }
            </style>

            <h4 style="margin: 0 0 16px 0; color: #293E4C;"><i class="fas fa-file-alt" style="color: #6f42c1; margin-right: 8px;"></i>Module Documentation</h4>
            <div id="module-docs-list" class="module-docs-list">
                <div style="text-align: center; padding: 20px; color: #6c757d;">Loading documents...</div>
            </div>
        `;

        // Fetch module documents
        loadModuleDocuments();
    }
}

function loadModuleDocuments() {
    const projectId = <?php echo $project_id; ?>;
    // Fetch documents with type: modules
    fetch(`get_project_documents.php?project_id=${projectId}&document_type=modules`)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('module-docs-list');
            if (!data.success || !data.documents || data.documents.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d; font-style: italic;">No module documentation uploaded yet</div>';
                return;
            }

            let html = '';
            data.documents.forEach(doc => {
                const ext = (doc.original_name || '').split('.').pop().toLowerCase();
                let icon = '📄';
                if (['pdf'].includes(ext)) icon = '📕';
                else if (['doc', 'docx'].includes(ext)) icon = '📘';
                else if (['xls', 'xlsx', 'csv'].includes(ext)) icon = '📗';
                else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) icon = '🖼️';

                html += `
                    <div class="module-doc-item">
                        <div class="doc-icon">${icon}</div>
                        <div class="doc-info">
                            <div class="doc-name">${doc.original_name || 'Document'}</div>
                            <div class="doc-type">${doc.document_sub_type || 'Module Documentation'}</div>
                        </div>
                        <div class="doc-actions">
                            <a href="${doc.file_path}" target="_blank"><i class="fas fa-download"></i> View</a>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            document.getElementById('module-docs-list').innerHTML = '<div style="text-align: center; padding: 20px; color: #dc3545;">Error loading documents</div>';
        });
}

// Shipping Breakdown modal
const shippingBreakdown = <?php echo json_encode($detailed_breakdown ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
function showShippingBreakdown(type, onlyKey){
    const modal = document.getElementById('shippingModal');
    const title = document.getElementById('shippingModalTitle');
    const content = document.getElementById('shippingModalContent');
    title.textContent = (onlyKey ? onlyKey : type) + ' - Detailed Breakdown';
    content.innerHTML = generateShippingContent(type, onlyKey);
    modal.style.display = 'block';
}
function closeShippingModal(){
    const modal = document.getElementById('shippingModal');
    if(modal) modal.style.display = 'none';
}
function generateShippingContent(filter, onlyKey){
    let html='<div>';
    let has=false;
    
    // Handle special case for "Delivered" status
    if(filter === 'Delivered') {
        has = true;
        const totalDeliveredRaw = <?php echo (int)($delivered_raw_total ?? 0); ?>;
        const totalDeliveredMW = <?php echo $delivered_combined; ?>;
        const totalPallets = Math.round(totalDeliveredRaw / 30);
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#e8f5e8;border-radius:12px;border-left:4px solid #28a745;">` +
               `<h4 style="margin-top:0;color:#28a745;">🎉 Delivered to Project</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalPallets}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalDeliveredRaw.toLocaleString()}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalDeliveredMW.toFixed(2)}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show wattage breakdown for delivered modules        
        const deliveredBreakdown = <?php echo json_encode($delivered_by_wattage ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        if(deliveredBreakdown.length > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#28a745;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
            deliveredBreakdown.forEach(function(item) {
                const mws = ((item.modules * item.wattage) / 1000000).toFixed(2);
                let breakdownText = `${item.wattage}W: ${item.pallets} pallets • ${item.modules.toLocaleString()} modules • ${mws} MWs`;
                if(item.damaged_pallets > 0) {
                    breakdownText += ` (${item.damaged_pallets} damaged pallets, ${item.damaged_modules.toLocaleString()} damaged modules)`;
                }
                html += `<li style="padding:8px 0;border-bottom:1px solid #eee;">${breakdownText}</li>`;
            });
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="manage_deliveries?project_id=<?php echo $project_id; ?>" class="customer-modal-btn">View Deliveries</a>` +
               `</div>`;
        html += '</div>';
    } else if (filter === 'Exceptions') {
        // Handle Exceptions (Damaged Pallets) for admin
        has = true;
        const exceptionsData = {
            damaged: <?php echo ($status_totals['Damaged']['pallets'] ?? 0); ?>,
            damaged_modules: <?php echo ($status_totals['Damaged']['modules'] ?? 0); ?>
        };
        
        const totalExceptionPallets = exceptionsData.damaged;
        const totalExceptionModules = exceptionsData.damaged_modules;
        
        html += `<div style="margin-bottom:20px;padding:15px;background:#fff3e0;border-radius:8px;border-left:4px solid #f57c00;">` +
               `<h4 style="margin-top:0;color:#e65100;">⚠️ Module Exceptions</h4>` +
               `<p><strong>Total:</strong> ${totalExceptionPallets} pallets, ${totalExceptionModules.toLocaleString()} modules</p>`;
        
        // Show exception type breakdown
        if (exceptionsData.damaged > 0) {
            html += '<p><strong>Exception Breakdown:</strong></p><ul>';
            html += `<li style="color:#d32f2f;"><strong>Damaged:</strong> ${exceptionsData.damaged} pallets (${exceptionsData.damaged_modules.toLocaleString()} modules)</li>`;
            html += '</ul>';
        }
        
        html += `<div style="text-align:center;margin-top:15px;">` +
               `<a href="warranty.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#f57c00;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">View Exceptions</a>` +
               `</div>`;
        html += '</div>';
    } else {
        // Handle other shipping statuses
        for(const key in shippingBreakdown){
            if(onlyKey && key !== onlyKey) continue;
            if(key.includes(filter)){
                has=true;
                const data=shippingBreakdown[key];
                html+=`<div style="margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:8px;border-left:4px solid #488C9A;">`+
                     `<h4 style="margin-top:0;">${key}</h4>`+
                     `<p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>`;
                if(data.wattage_breakdown && Object.keys(data.wattage_breakdown).length>0){
                    html+='<p><strong>Wattage Breakdown:</strong></p><ul>';
                    for(const w in data.wattage_breakdown){
                        const d=data.wattage_breakdown[w];
                        html+=`<li>${w}W: ${d.pallets} pallets (${d.modules.toLocaleString()} modules)</li>`;
                    }
                    html+='</ul>';
                }
                if(filter==='In Transit to Warehouse' && data.warehouse_id){
                    html+=`<a href="warehouse_info.php?warehouse_id=${data.warehouse_id}&project_id=<?php echo $project_id; ?>" class="modal-action" style="display:inline-block;margin-top:8px;background:#488C9A;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;">Receive into Warehouse</a>`;
                }
                html+='</div>';
            }
        }
        
        if(!has){html+='<p style="text-align:center;color:#666;font-style:italic;">No data.</p>';}
        
        // Add action buttons for different statuses
        if(filter==='At Manufacturer'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=At%20Manufacturer" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a></div>`;
        }else if(filter==='On Water'){
            // For On Water status, link to container tracking
            html+=`<div style="text-align:center;margin-top:15px;">`;
            html+=`<p style="color:#666;margin-bottom:10px;">Pallets are in ocean transit. Update vessel position and waypoints from Container ETA Tracker.</p>`;
            html+=`<a href="container_tracking.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Open Container ETA Tracker</a>`;
            html+=`</div>`;
        }else if(filter==='Customs Hold'){
            html+=`<div style="text-align:center;margin-top:15px;">`;
            html+=`<p style="color:#666;margin-bottom:10px;">Pallets are being reviewed by customs. Use the customs hold queue to release eligible pallets.</p>`;
            for(const key in shippingBreakdown){
                if(key.includes('Customs Hold') && shippingBreakdown[key].warehouse_id){
                    html+=`<a href="warehouse_info.php?warehouse_id=${shippingBreakdown[key].warehouse_id}&project_id=<?php echo $project_id; ?>&tab=customsHold" class="modal-action" style="background:#dc2626;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Manage Customs Hold</a>`;
                    break;
                }
            }
            html+=`</div>`;
        }else if(filter==='Cleared Customs'){
            // For Cleared Customs status, link to warehouse inventory for drayage shipment
            html+=`<div style="text-align:center;margin-top:15px;">`;
            html+=`<p style="color:#666;margin-bottom:10px;">Pallets have cleared customs. Create a drayage shipment to move them to their destination.</p>`;
            // Find the port warehouse for this project's cleared customs pallets
            for(const key in shippingBreakdown){
                if(key.includes('Cleared Customs') && shippingBreakdown[key].warehouse_id){
                    html+=`<a href="warehouse_info.php?warehouse_id=${shippingBreakdown[key].warehouse_id}&project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Create Drayage Shipment</a>`;
                    break;
                }
            }
            html+=`</div>`;
        }else if(filter==='In Warehouse'){
            // Collect warehouse IDs from breakdown for this status
            const warehouseLinks = [];
            for(const key in shippingBreakdown){
                if(onlyKey && key !== onlyKey) continue;
                if(key.includes('In Warehouse') && shippingBreakdown[key].warehouse_id){
                    warehouseLinks.push({name: key, id: shippingBreakdown[key].warehouse_id});
                }
            }
            html+=`<div style="text-align:center;margin-top:15px;">`;
            if(warehouseLinks.length === 1){
                html+=`<a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=In%20Warehouse&warehouse_id=${warehouseLinks[0].id}" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a>`;
            } else if(warehouseLinks.length > 1){
                warehouseLinks.forEach(wh => {
                    html+=`<a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=In%20Warehouse&warehouse_id=${wh.id}" class="modal-action" style="display:inline-block;background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin:5px;">Create Shipment from ${wh.name}</a>`;
                });
            } else {
                html+=`<a href="create_shipment.php?project_id=<?php echo $project_id; ?>&status_filter=In%20Warehouse" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Create Shipment</a>`;
            }
            html+=`</div>`;
        }else if(filter==='In Transit to Project'){
            html+=`<div style="text-align:center;margin-top:15px;"><a href="scheduling.php?project_id=<?php echo $project_id; ?>" class="modal-action" style="background:#488C9A;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">Schedule/Receive Deliveries</a></div>`;
        }
    }
    
    html+='</div>';
    return html;
}

// Admin warehousing functionality
const warehousesWithInventory = <?php echo json_encode($warehouses_with_inventory ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
function handleAdminWarehousing() {
    const projectId = <?php echo $project_id; ?>;
    
    if (warehousesWithInventory.length === 0) {
        alert('No inventory found for this project in any warehouse.');
        return;
    } else if (warehousesWithInventory.length === 1) {
        // Single warehouse - go directly to warehouse_info
        window.location.href = 'warehouse_info.php?warehouse_id=' + warehousesWithInventory[0].id + '&project_id=' + projectId;
    } else {
        // Multiple warehouses - show warehouse selection page
        showWarehouseSelectionModal();
    }
}

function showWarehouseSelectionModal() {
    const modal = document.createElement('div');
    modal.className = 'warehouse-selection-modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Warehouse to Manage</h3>
                <span class="close-modal" onclick="closeWarehouseModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>This project has inventory in multiple warehouses. Select a warehouse to manage:</p>
                <div class="warehouse-selection-grid">
                    ${warehousesWithInventory.map(wh => `
                        <div class="warehouse-selection-card" onclick="goToWarehouseManagement(${wh.id})">
                            <h4>${wh.name}</h4>
                            <p><strong>Address:</strong> ${wh.address || 'N/A'}</p>
                            <p><strong>Pallets Stored:</strong> ${wh.pallets_in_warehouse || 0}</p>
                            <p><strong>Modules Stored:</strong> ${wh.modules_in_warehouse || 0}</p>
                            <p><strong>Pallets In Transit:</strong> ${wh.pallets_in_transit_to_wh || 0}</p>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.style.display = 'block';
}

function closeWarehouseModal() {
    const modal = document.querySelector('.warehouse-selection-modal');
    if (modal) {
        modal.remove();
    }
}

function goToWarehouseManagement(warehouseId) {
    const projectId = <?php echo $project_id; ?>;
    window.location.href = 'warehouse_info.php?warehouse_id=' + warehouseId + '&project_id=' + projectId;
}
<?php endif; ?>

<?php if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Customer view functions
let currentFilter = 'mws'; // Global filter state

// Conversion availability and actual actuals for pallets/truckloads
window.conversionAvailability = {
    isAdmin: false,
    modulesPerPalletAvailable: <?php echo ($average_modules_per_pallet !== null && $average_modules_per_pallet > 0) ? 'true' : 'false'; ?>,
    avgModulesPerPallet: <?php echo ($average_modules_per_pallet !== null && $average_modules_per_pallet > 0) ? (int)$average_modules_per_pallet : 'null'; ?>,
    palletsPerTruckAvailable: <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? 'true' : 'false'; ?>,
    avgPalletsPerTruck: <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? (int)$average_pallets_per_truck : 'null'; ?>,
    modulesPerTruckAvailable: <?php echo ($average_modules_per_truck !== null && $average_modules_per_truck > 0) ? 'true' : 'false'; ?>,
    avgModulesPerTruck: <?php echo ($average_modules_per_truck !== null && $average_modules_per_truck > 0) ? (int)$average_modules_per_truck : 'null'; ?>,
    wattageModulesPerPallet: <?php
        $mpp_by_wattage_avg = [];
        foreach ($mpp_by_wattage as $w => $sum_val) {
            $den = $modules_by_wattage_for_mpp[$w] ?? 0;
            if ($den > 0) $mpp_by_wattage_avg[$w] = round($sum_val / $den);
        }
        echo json_encode($mpp_by_wattage_avg);
    ?>
};

// Actual pallets by status for Module Delivery Status table
window.actualStatusData = {
    pallets: {
        main: <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>,
        sub: <?php echo json_encode($pallets_sub_rows_status ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>
    }
};

function showView(viewId) {
    if (viewId === 'financial-info') {
        window.location.href = 'project_cost_details.php?project_id=<?php echo $project_id; ?>';
        return;
    }

    const viewMap = {
        'progress-info': 'tab-timeline',
        'project-progress': 'tab-timeline',
        'site-info': 'tab-site',
        'module-info': 'tab-modules',
        'delivery-info': 'tab-deliveries'
    };
    const tabId = viewMap[viewId];
    if (tabId) {
        activateTab(tabId);
        if (typeof syncFiltersToState === 'function') {
            syncFiltersToState();
        }
    }
}

function toggleSubRows(cls){
    var rows = document.getElementsByClassName(cls);
    for(var i=0; i<rows.length; i++){
        if(rows[i].style.display==='' || rows[i].style.display==='none'){
            rows[i].style.display='table-row';
        } else {
            rows[i].style.display='none';
        }
    }
}

// Sync all filter buttons and data to the current state
function syncFiltersToState() {
    // Update all filter buttons to match current state
    document.querySelectorAll('.unit-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-unit') === currentFilter) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    // Update all sections with current filter
    updateCustomerShippingBoxes(currentFilter, document.getElementById('project-progress'));
    updateCustomerShippingBoxes(currentFilter, document.getElementById('delivery-info'));
    
    // Handle large numbers after updating
    setTimeout(handleLargeNumbers, 100);
    
    // Update timeline remaining text
    updateTimelineRemainingText(currentFilter);
}

// Update timeline remaining text based on current filter
function updateTimelineRemainingText(filterType) {
    const timelineTexts = document.querySelectorAll('.timeline-remaining-text');
    if (!timelineTexts.length) return;
    
    // Check if project is completed
    const isCompleted = <?php echo $step5_completed ? 'true' : 'false'; ?>;
    if (isCompleted) return; // Don't update if project is already completed
    
    // Get project data for calculations  
    const totalModules = <?php echo (int)($total_raw_modules ?? 0); ?>;
    const deliveredModules = <?php echo (int)($delivered_raw_total ?? 0); ?>;
    const projectSizeMW = <?php echo is_numeric($project_size_mw) ? round($project_size_mw, 2) : 0; ?>;
    
    // Calculate delivered MW (approximate based on delivered/total ratio)
    const deliveryRatio = totalModules > 0 ? (deliveredModules / totalModules) : 0;
    const deliveredMW = projectSizeMW * deliveryRatio;
    
    // Calculate totals and remaining for each filter type
    let remaining, unit;
    
    switch(filterType) {
        case 'modules':
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
            break;
            
        case 'mws':
            remaining = projectSizeMW - deliveredMW;
            unit = 'MWs';
            remaining = Math.max(0, parseFloat(remaining.toFixed(2)));
            break;
            
        case 'pallets':
            // Use actual pallet counts from the database
            const actualPalletData = <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
            const totalActualPallets = actualPalletData.total_order;
            const deliveredActualPallets = actualPalletData.delivered;
            remaining = Math.max(0, totalActualPallets - deliveredActualPallets);
            unit = 'pallets';
            break;
            
        case 'truckloads':
            // Estimate truckloads based on modules (using average modules per truck if available)  
            const avgModulesPerTruck = <?php echo ($weighted_avg_modules_per_truck !== null && $weighted_avg_modules_per_truck > 0) ? (int)$weighted_avg_modules_per_truck : 500; ?>;
            const totalTrucks = Math.ceil(totalModules / avgModulesPerTruck);
            const deliveredTrucks = Math.floor(deliveredModules / avgModulesPerTruck);
            remaining = Math.max(0, totalTrucks - deliveredTrucks);
            unit = 'truckloads';
            break;
            
        default:
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
    }
    
    // Update all timeline remaining text elements
    timelineTexts.forEach(element => {
        const countSpan = element.querySelector('.remaining-count');
        const unitSpan = element.querySelector('.remaining-unit');
        
        if (countSpan && unitSpan) {
            // Format the number properly
            const formattedRemaining = filterType === 'mws' ? 
                remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) :
                remaining.toLocaleString('en-US');
                
            countSpan.textContent = formattedRemaining;
            unitSpan.textContent = unit;
        }
    });
}

// Customer Unit Filter functionality
function initializeCustomerUnitFilters() {
    const filterSections = document.querySelectorAll('.unit-filters');
    
    filterSections.forEach(section => {
        const filterButtons = section.querySelectorAll('.unit-filter-btn');
        
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const filterType = this.getAttribute('data-unit');
                
                // Update global filter state
                currentFilter = filterType;
                
                // Sync all sections to new filter
                syncFiltersToState();
            });
        });
    });
}

function updateCustomerShippingBoxes(filterType, section) {
    if (!section) return;
    
    // Update shipping boxes
    const shippingBoxes = section.querySelectorAll('.shipping-box-customer');
    
    shippingBoxes.forEach(box => {
        const statusCount = box.querySelector('.status-count');
        const statusUnit = box.querySelector('.status-unit');
        
        if (statusCount && statusUnit) {
            let value, unit;
            
            switch(filterType) {
                case 'modules':
                    value = parseInt(box.getAttribute('data-modules') || 0);
                    unit = 'modules';
                    break;
                case 'truckloads':
                    value = parseFloat(box.getAttribute('data-truckloads') || 0);
                    unit = 'truckloads';
                    break;
                case 'mws':
                    value = parseFloat(box.getAttribute('data-mws') || 0);
                    unit = 'MWs';
                    break;
                case 'pallets':
                default:
                    value = parseInt(box.getAttribute('data-pallets') || 0);
                    unit = 'pallets';
                    break;
            }
            
            // Format the value
            let displayText;
            if (isNaN(value)) {
                displayText = 'N/A';
            } else if (filterType === 'truckloads' || filterType === 'mws') {
                const decimals = filterType === 'mws' ? 2 : 1;
                displayText = value % 1 === 0 ? value.toString() : value.toFixed(decimals);
            } else {
                displayText = Math.round(value).toLocaleString();
            }

            statusCount.textContent = displayText;
            statusUnit.textContent = unit;

            // Add class for large numbers to shrink font
            statusCount.classList.remove('large-number', 'very-large-number');
            if (displayText.length >= 7) {
                statusCount.classList.add('very-large-number');
            } else if (displayText.length >= 5) {
                statusCount.classList.add('large-number');
            }
        }
    });

    // Update table data if this is the delivery-info section
    if (section.id === 'delivery-info') {
        updateDeliveryTables(filterType);
    }
}

function updateDeliveryTables(filterType) {
    // Update table data based on filter type
    const table1 = document.getElementById('table1');
    const table2 = document.getElementById('table2');
    
    if (!table1 || !table2) return;
    
    // Store original data if not already stored
    if (!window.originalTableData) {
        window.originalTableData = {
            mw: {
                total_order: <?php echo $total_order_combined; ?>,
                delivered: <?php echo $delivered_combined; ?>,
                at_manufacturer: <?php echo $at_manufacturer_combined; ?>,
                in_warehouse: <?php echo $in_warehouse_combined; ?>,
                on_water: <?php echo $on_water_combined; ?>,
                customs_hold: <?php echo $customs_hold_combined; ?>,
                cleared_customs: <?php echo $cleared_customs_combined; ?>,
                in_transit_to_warehouse: <?php echo $in_transit_to_warehouse_combined; ?>,
                in_transit_to_project: <?php echo $in_transit_to_project_combined; ?>,
                weeks: [<?php foreach($anticipated_quantities_combined as $q): ?><?php echo $q; ?>,<?php endforeach; ?>],
                sub_rows: {
                    <?php foreach($sub_rows as $lbl => $sr): ?>
                    '<?php echo addslashes($lbl); ?>': {
                        total_order: <?php echo $sr['total_order']; ?>,
                        delivered: <?php echo $sr['delivered']; ?>,
                        weeks: [<?php foreach($sr['anticipated_quantities'] as $v): ?><?php echo $v; ?>,<?php endforeach; ?>]
                    },
                    <?php endforeach; ?>
                },
                sub_rows_status: {
                    <?php foreach($sub_rows_status as $lbl => $srs): ?>
                    '<?php echo addslashes($lbl); ?>': {
                        total_order: <?php echo $srs['total_order']; ?>,
                        delivered: <?php echo $srs['delivered']; ?>,
                        at_manufacturer: <?php echo ($srs['at_manufacturer'] ?? 0); ?>,
                        in_warehouse: <?php echo $srs['in_warehouse']; ?>,
                        on_water: <?php echo $srs['on_water']; ?>,
                        customs_hold: <?php echo ($srs['customs_hold'] ?? 0); ?>,
                        cleared_customs: <?php echo $srs['cleared_customs']; ?>,
                        in_transit_to_warehouse: <?php echo ($srs['in_transit_to_warehouse'] ?? 0); ?>,
                        in_transit_to_project: <?php echo ($srs['in_transit_to_project'] ?? 0); ?>,
                    },
                    <?php endforeach; ?>
                }
            }
        };
        
        // Convert MWs to modules for module data
        const avgWattage = <?php 
            if (!empty($wattages)) {
                echo array_sum($wattages) / count($wattages);
            } else {
                echo 555; // Default wattage if none available
            }
        ?>;
        
        window.originalTableData.modules = {
            total_order: Math.round(window.originalTableData.mw.total_order * 1000000 / avgWattage),
            delivered: Math.round(window.originalTableData.mw.delivered * 1000000 / avgWattage),
            at_manufacturer: Math.round(window.originalTableData.mw.at_manufacturer * 1000000 / avgWattage),
            in_warehouse: Math.round(window.originalTableData.mw.in_warehouse * 1000000 / avgWattage),
            on_water: Math.round(window.originalTableData.mw.on_water * 1000000 / avgWattage),
            customs_hold: Math.round(window.originalTableData.mw.customs_hold * 1000000 / avgWattage),
            cleared_customs: Math.round(window.originalTableData.mw.cleared_customs * 1000000 / avgWattage),
            in_transit_to_warehouse: Math.round(window.originalTableData.mw.in_transit_to_warehouse * 1000000 / avgWattage),
            in_transit_to_project: Math.round(window.originalTableData.mw.in_transit_to_project * 1000000 / avgWattage),
            weeks: window.originalTableData.mw.weeks.map(w => Math.round(w * 1000000 / avgWattage)),
            sub_rows: {},
            sub_rows_status: {}
        };
        
        // Calculate pallets data (assuming 30 modules per pallet)
        const modulesPerPallet = 30;
        window.originalTableData.pallets = {
            total_order: Math.round(window.originalTableData.modules.total_order / modulesPerPallet),
            delivered: Math.round(window.originalTableData.modules.delivered / modulesPerPallet),
            at_manufacturer: Math.round(window.originalTableData.modules.at_manufacturer / modulesPerPallet),
            in_warehouse: Math.round(window.originalTableData.modules.in_warehouse / modulesPerPallet),
            on_water: Math.round(window.originalTableData.modules.on_water / modulesPerPallet),
            customs_hold: Math.round(window.originalTableData.modules.customs_hold / modulesPerPallet),
            cleared_customs: Math.round(window.originalTableData.modules.cleared_customs / modulesPerPallet),
            in_transit_to_warehouse: Math.round(window.originalTableData.modules.in_transit_to_warehouse / modulesPerPallet),
            in_transit_to_project: Math.round(window.originalTableData.modules.in_transit_to_project / modulesPerPallet),
            weeks: window.originalTableData.modules.weeks.map(w => Math.round(w / modulesPerPallet)),
            sub_rows: {},
            sub_rows_status: {}
        };
        
        // Calculate truckloads data only if a pallets-per-truck value is available; otherwise leave as empty
        const palletsPerTruck = (window.conversionAvailability && window.conversionAvailability.avgPalletsPerTruck) ? window.conversionAvailability.avgPalletsPerTruck : null;
        window.originalTableData.truckloads = { sub_rows: {}, sub_rows_status: {}, weeks: [], total_order: 0, delivered: 0 };
        if (palletsPerTruck) {
            window.originalTableData.truckloads = {
                total_order: (window.originalTableData.pallets.total_order / palletsPerTruck).toFixed(1),
                delivered: (window.originalTableData.pallets.delivered / palletsPerTruck).toFixed(1),
                at_manufacturer: (window.originalTableData.pallets.at_manufacturer / palletsPerTruck).toFixed(1),
                in_warehouse: (window.originalTableData.pallets.in_warehouse / palletsPerTruck).toFixed(1),
                on_water: (window.originalTableData.pallets.on_water / palletsPerTruck).toFixed(1),
                customs_hold: (window.originalTableData.pallets.customs_hold / palletsPerTruck).toFixed(1),
                cleared_customs: (window.originalTableData.pallets.cleared_customs / palletsPerTruck).toFixed(1),
                in_transit_to_warehouse: (window.originalTableData.pallets.in_transit_to_warehouse / palletsPerTruck).toFixed(1),
                in_transit_to_project: (window.originalTableData.pallets.in_transit_to_project / palletsPerTruck).toFixed(1),
                weeks: window.originalTableData.pallets.weeks.map(w => (w / palletsPerTruck).toFixed(1)),
                sub_rows: {},
                sub_rows_status: {}
            };
        }
        
        // Convert sub_rows data
        for (const [key, data] of Object.entries(window.originalTableData.mw.sub_rows)) {
            const wattageMatch = key.match(/(\d+)W/);
            const wattage = wattageMatch ? parseFloat(wattageMatch[1]) : avgWattage;

            window.originalTableData.modules.sub_rows[key] = {
                total_order: Math.round(data.total_order * 1000000 / wattage),
                delivered: Math.round(data.delivered * 1000000 / wattage),
                weeks: data.weeks.map(w => Math.round(w * 1000000 / wattage))
            };

            window.originalTableData.pallets.sub_rows[key] = {
                total_order: Math.round(window.originalTableData.modules.sub_rows[key].total_order / modulesPerPallet),
                delivered: Math.round(window.originalTableData.modules.sub_rows[key].delivered / modulesPerPallet),
                weeks: window.originalTableData.modules.sub_rows[key].weeks.map(w => Math.round(w / modulesPerPallet))
            };

            if (palletsPerTruck) {
                window.originalTableData.truckloads.sub_rows[key] = {
                    total_order: (window.originalTableData.pallets.sub_rows[key].total_order / palletsPerTruck).toFixed(1),
                    delivered: (window.originalTableData.pallets.sub_rows[key].delivered / palletsPerTruck).toFixed(1),
                    weeks: window.originalTableData.pallets.sub_rows[key].weeks.map(w => (w / palletsPerTruck).toFixed(1))
                };
            }
        }

        // Convert sub_rows_status data
        for (const [key, data] of Object.entries(window.originalTableData.mw.sub_rows_status)) {
            const wattageMatch = key.match(/(\d+)W/);
            const wattage = wattageMatch ? parseFloat(wattageMatch[1]) : avgWattage;

            window.originalTableData.modules.sub_rows_status[key] = {
                total_order: Math.round(data.total_order * 1000000 / wattage),
                delivered: Math.round(data.delivered * 1000000 / wattage),
                at_manufacturer: Math.round(data.at_manufacturer * 1000000 / wattage),
                in_warehouse: Math.round(data.in_warehouse * 1000000 / wattage),
                on_water: Math.round(data.on_water * 1000000 / wattage),
                customs_hold: Math.round((data.customs_hold || 0) * 1000000 / wattage),
                cleared_customs: Math.round(data.cleared_customs * 1000000 / wattage),
                in_transit_to_warehouse: Math.round(data.in_transit_to_warehouse * 1000000 / wattage),
                in_transit_to_project: Math.round(data.in_transit_to_project * 1000000 / wattage)
            };

            window.originalTableData.pallets.sub_rows_status[key] = {
                total_order: Math.round(window.originalTableData.modules.sub_rows_status[key].total_order / modulesPerPallet),
                delivered: Math.round(window.originalTableData.modules.sub_rows_status[key].delivered / modulesPerPallet),
                at_manufacturer: Math.round(window.originalTableData.modules.sub_rows_status[key].at_manufacturer / modulesPerPallet),
                in_warehouse: Math.round(window.originalTableData.modules.sub_rows_status[key].in_warehouse / modulesPerPallet),
                on_water: Math.round(window.originalTableData.modules.sub_rows_status[key].on_water / modulesPerPallet),
                customs_hold: Math.round((window.originalTableData.modules.sub_rows_status[key].customs_hold || 0) / modulesPerPallet),
                cleared_customs: Math.round(window.originalTableData.modules.sub_rows_status[key].cleared_customs / modulesPerPallet),
                in_transit_to_warehouse: Math.round(window.originalTableData.modules.sub_rows_status[key].in_transit_to_warehouse / modulesPerPallet),
                in_transit_to_project: Math.round(window.originalTableData.modules.sub_rows_status[key].in_transit_to_project / modulesPerPallet)
            };

            if (palletsPerTruck) {
                window.originalTableData.truckloads.sub_rows_status[key] = {
                    total_order: (window.originalTableData.pallets.sub_rows_status[key].total_order / palletsPerTruck).toFixed(1),
                    delivered: (window.originalTableData.pallets.sub_rows_status[key].delivered / palletsPerTruck).toFixed(1),
                    at_manufacturer: (window.originalTableData.pallets.sub_rows_status[key].at_manufacturer / palletsPerTruck).toFixed(1),
                    in_warehouse: (window.originalTableData.pallets.sub_rows_status[key].in_warehouse / palletsPerTruck).toFixed(1),
                    on_water: (window.originalTableData.pallets.sub_rows_status[key].on_water / palletsPerTruck).toFixed(1),
                    customs_hold: ((window.originalTableData.pallets.sub_rows_status[key].customs_hold || 0) / palletsPerTruck).toFixed(1),
                    cleared_customs: (window.originalTableData.pallets.sub_rows_status[key].cleared_customs / palletsPerTruck).toFixed(1),
                    in_transit_to_warehouse: (window.originalTableData.pallets.sub_rows_status[key].in_transit_to_warehouse / palletsPerTruck).toFixed(1),
                    in_transit_to_project: (window.originalTableData.pallets.sub_rows_status[key].in_transit_to_project / palletsPerTruck).toFixed(1)
                };
            }
        }
    }
    
    // Determine which data to use based on filter
    let dataType, decimals;
    switch(filterType) {
        case 'mws':
            dataType = 'mw';
            decimals = 2;
            break;
        case 'modules':
            dataType = 'modules';
            decimals = 0;
            break;
        case 'pallets':
            dataType = 'pallets';
            decimals = 0;
            break;
        case 'truckloads':
            dataType = 'truckloads';
            decimals = 1;
            break;
        default:
            dataType = 'mw';
            decimals = 2;
    }
    const data = window.originalTableData[dataType];
    
    // Update main rows in both tables
    const mainRow1 = table1.querySelector('tbody tr:first-child');
    if (mainRow1) {
        const cells = mainRow1.querySelectorAll('td');
        if (cells.length >= 3) {
            if (filterType === 'pallets') {
                if (window.conversionAvailability.modulesPerPalletAvailable && window.conversionAvailability.avgModulesPerPallet) {
                    const mpp = window.conversionAvailability.avgModulesPerPallet;
                    const src = window.originalTableData.modules;
                    cells[1].textContent = formatNumber(src.total_order / mpp, 0);
                    cells[2].textContent = formatNumber(src.delivered / mpp, 0);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(src.weeks[i] / mpp, 0);
                    }
                } else {
                    // N/A when missing conversion
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else if (filterType === 'truckloads') {
                const src = window.originalTableData.modules;
                if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck && window.conversionAvailability.modulesPerPalletAvailable && window.conversionAvailability.avgModulesPerPallet) {
                    const mpp = window.conversionAvailability.avgModulesPerPallet;
                    const ppt = window.conversionAvailability.avgPalletsPerTruck;
                    cells[1].textContent = formatNumber((src.total_order / mpp) / ppt, 1);
                    cells[2].textContent = formatNumber((src.delivered / mpp) / ppt, 1);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber((src.weeks[i] / mpp) / ppt, 1);
                    }
                } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                    const mpt = window.conversionAvailability.avgModulesPerTruck;
                    cells[1].textContent = formatNumber(src.total_order / mpt, 1);
                    cells[2].textContent = formatNumber(src.delivered / mpt, 1);
                    for (let i = 0; i < src.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(src.weeks[i] / mpt, 1);
                    }
                } else {
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else {
                cells[1].textContent = formatNumber(data.total_order, decimals);
                cells[2].textContent = formatNumber(data.delivered, decimals);
                // Update week cells
                for (let i = 0; i < data.weeks.length && i + 3 < cells.length; i++) {
                    cells[i + 3].textContent = formatNumber(data.weeks[i], decimals);
                }
            }
        }
    }
    
    const mainRow2 = table2.querySelector('tbody tr:first-child');
    if (mainRow2) {
        const cells = mainRow2.querySelectorAll('td');
        if (cells.length >= 3) {
            if (filterType === 'pallets') {
                const pal = window.actualStatusData.pallets.main;
                let idx = 1;
                cells[idx++].textContent = formatNumber(pal.total_order || 0, 0);
                cells[idx++].textContent = formatNumber(pal.at_manufacturer || 0, 0);
                if (data.on_water > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.on_water || 0, 0);
                if (data.customs_hold > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.customs_hold || 0, 0);
                if (data.cleared_customs > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.cleared_customs || 0, 0);
                if (data.in_transit_to_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_transit_to_warehouse || 0, 0);
                if (data.in_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_warehouse || 0, 0);
                if (data.in_transit_to_project > 0 && cells[idx]) cells[idx++].textContent = formatNumber(pal.in_transit_to_project || 0, 0);
                if (cells[idx]) cells[idx].textContent = formatNumber(pal.delivered || 0, 0);
            } else if (filterType === 'truckloads') {
                const pal = window.actualStatusData.pallets.main;
                let tl = null;
                if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck) {
                    tl = {
                        total_order: (pal.total_order || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        at_manufacturer: (pal.at_manufacturer || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        on_water: (pal.on_water || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        customs_hold: (pal.customs_hold || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        cleared_customs: (pal.cleared_customs || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_transit_to_warehouse: (pal.in_transit_to_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_warehouse: (pal.in_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        in_transit_to_project: (pal.in_transit_to_project || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        delivered: (pal.delivered || 0) / window.conversionAvailability.avgPalletsPerTruck,
                    };
                } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                    tl = {
                        total_order: (data.total_order || 0) / window.conversionAvailability.avgModulesPerTruck,
                        at_manufacturer: (data.at_manufacturer || 0) / window.conversionAvailability.avgModulesPerTruck,
                        on_water: (data.on_water || 0) / window.conversionAvailability.avgModulesPerTruck,
                        customs_hold: (data.customs_hold || 0) / window.conversionAvailability.avgModulesPerTruck,
                        cleared_customs: (data.cleared_customs || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_transit_to_warehouse: (data.in_transit_to_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_warehouse: (data.in_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                        in_transit_to_project: (data.in_transit_to_project || 0) / window.conversionAvailability.avgModulesPerTruck,
                        delivered: (data.delivered || 0) / window.conversionAvailability.avgModulesPerTruck,
                    };
                }
                let idx = 1;
                if (!tl) {
                    while (idx < cells.length) { cells[idx++].textContent = 'N/A'; }
                } else {
                    cells[idx++].textContent = formatNumber(tl.total_order, 1);
                    cells[idx++].textContent = formatNumber(tl.at_manufacturer || 0, 1);
                    if (data.on_water > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.on_water || 0, 1);
                    if (data.customs_hold > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.customs_hold || 0, 1);
                    if (data.cleared_customs > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.cleared_customs || 0, 1);
                    if (data.in_transit_to_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_transit_to_warehouse || 0, 1);
                    if (data.in_warehouse > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_warehouse || 0, 1);
                    if (data.in_transit_to_project > 0 && cells[idx]) cells[idx++].textContent = formatNumber(tl.in_transit_to_project || 0, 1);
                    if (cells[idx]) cells[idx].textContent = formatNumber(tl.delivered || 0, 1);
                }
            } else {
                // Default (MWs/modules) with no damaged parentheses
                cells[1].textContent = formatNumber(data.total_order, decimals);
                cells[2].textContent = formatNumber((data.at_manufacturer || 0), decimals);
                let cellIndex = 3;
                if (data.on_water > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.on_water, decimals); }
                if (data.customs_hold > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.customs_hold, decimals); }
                if (data.cleared_customs > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.cleared_customs, decimals); }
                if (data.in_transit_to_warehouse > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_transit_to_warehouse, decimals); }
                if (data.in_warehouse > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_warehouse, decimals); }
                if (data.in_transit_to_project > 0 && cells[cellIndex]) { cells[cellIndex++].textContent = formatNumber(data.in_transit_to_project, decimals); }
                if (cells[cellIndex]) { cells[cellIndex].textContent = formatNumber(data.delivered, decimals); }
            }
        }
    }

    // Update sub rows in table1 (Next 5 Weeks)
    const subRows1 = table1.querySelectorAll('tr.delivery-row');
    subRows1.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            const wattageLabel = cells[0].textContent;
            const subModules = (window.originalTableData.modules.sub_rows || {})[wattageLabel];
            const subGeneric = (data.sub_rows || {})[wattageLabel];
            if (filterType === 'pallets') {
                const wMatch = wattageLabel.match(/(\d+)W/);
                const w = wMatch ? parseInt(wMatch[1], 10) : null;
                const mppMap = window.conversionAvailability.wattageModulesPerPallet || {};
                const mpp = (w && mppMap[w]) ? mppMap[w] : window.conversionAvailability.avgModulesPerPallet;
                if (subModules && mpp && window.conversionAvailability.modulesPerPalletAvailable) {
                    cells[1].textContent = formatNumber(subModules.total_order / mpp, 0);
                    cells[2].textContent = formatNumber(subModules.delivered / mpp, 0);
                    for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                        cells[i + 3].textContent = formatNumber(subModules.weeks[i] / mpp, 0);
                    }
                } else {
                    for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                }
            } else if (filterType === 'truckloads') {
                const wMatch = wattageLabel.match(/(\d+)W/);
                const w = wMatch ? parseInt(wMatch[1], 10) : null;
                const mppMap = window.conversionAvailability.wattageModulesPerPallet || {};
                const mpp = (w && mppMap[w]) ? mppMap[w] : window.conversionAvailability.avgModulesPerPallet;
                if (subModules) {
                    if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck && (mpp || window.conversionAvailability.modulesPerPalletAvailable)) {
                        const ppt = window.conversionAvailability.avgPalletsPerTruck;
                        const denom = (mpp && mpp > 0) ? mpp : null;
                        if (denom) {
                            cells[1].textContent = formatNumber((subModules.total_order / denom) / ppt, 1);
                            cells[2].textContent = formatNumber((subModules.delivered / denom) / ppt, 1);
                            for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                                cells[i + 3].textContent = formatNumber((subModules.weeks[i] / denom) / ppt, 1);
                            }
                        } else {
                            for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                        }
                    } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                        const mpt = window.conversionAvailability.avgModulesPerTruck;
                        cells[1].textContent = formatNumber(subModules.total_order / mpt, 1);
                        cells[2].textContent = formatNumber(subModules.delivered / mpt, 1);
                        for (let i = 0; i < subModules.weeks.length && i + 3 < cells.length; i++) {
                            cells[i + 3].textContent = formatNumber(subModules.weeks[i] / mpt, 1);
                        }
                    } else {
                        for (let i = 1; i < cells.length; i++) { cells[i].textContent = 'N/A'; }
                    }
                }
            } else if (subGeneric) {
                // Default (MWs/modules) direct
                cells[1].textContent = formatNumber(subGeneric.total_order, decimals);
                cells[2].textContent = formatNumber(subGeneric.delivered, decimals);
                for (let i = 0; i < subGeneric.weeks.length && i + 3 < cells.length; i++) {
                    cells[i + 3].textContent = formatNumber(subGeneric.weeks[i], decimals);
                }
            }
        }
    });

    // Update sub rows in Module Delivery Status table
    const subRows2 = table2.querySelectorAll('tr.status-row');
    subRows2.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
            const wattageLabel = cells[0].textContent;
            const subData = data.sub_rows_status[wattageLabel];
            if (subData) {
                if (filterType === 'pallets') {
                    const palSub = window.actualStatusData.pallets.sub[wattageLabel] || {};
                    let idx2 = 1;
                    cells[idx2++].textContent = formatNumber(palSub.total_order || 0, 0);
                    cells[idx2++].textContent = formatNumber(palSub.at_manufacturer || 0, 0);
                    if (data.on_water > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.on_water || 0, 0);
                    if (data.customs_hold > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.customs_hold || 0, 0);
                    if (data.cleared_customs > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.cleared_customs || 0, 0);
                    if (data.in_transit_to_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_transit_to_warehouse || 0, 0);
                    if (data.in_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_warehouse || 0, 0);
                    if (data.in_transit_to_project > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(palSub.in_transit_to_project || 0, 0);
                    if (cells[idx2]) cells[idx2].textContent = formatNumber(palSub.delivered || 0, 0);
                } else if (filterType === 'truckloads') {
                    let idx2 = 1;
                    let source;
                    if (window.conversionAvailability.palletsPerTruckAvailable && window.conversionAvailability.avgPalletsPerTruck) {
                        const palSub = window.actualStatusData.pallets.sub[wattageLabel] || {};
                        source = {
                            total_order: (palSub.total_order || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            at_manufacturer: (palSub.at_manufacturer || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            on_water: (palSub.on_water || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            customs_hold: (palSub.customs_hold || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            cleared_customs: (palSub.cleared_customs || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_transit_to_warehouse: (palSub.in_transit_to_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_warehouse: (palSub.in_warehouse || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            in_transit_to_project: (palSub.in_transit_to_project || 0) / window.conversionAvailability.avgPalletsPerTruck,
                            delivered: (palSub.delivered || 0) / window.conversionAvailability.avgPalletsPerTruck,
                        };
                    } else if (window.conversionAvailability.modulesPerTruckAvailable && window.conversionAvailability.avgModulesPerTruck) {
                        source = {
                            total_order: (subData.total_order || 0) / window.conversionAvailability.avgModulesPerTruck,
                            at_manufacturer: (subData.at_manufacturer || 0) / window.conversionAvailability.avgModulesPerTruck,
                            on_water: (subData.on_water || 0) / window.conversionAvailability.avgModulesPerTruck,
                            customs_hold: (subData.customs_hold || 0) / window.conversionAvailability.avgModulesPerTruck,
                            cleared_customs: (subData.cleared_customs || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_transit_to_warehouse: (subData.in_transit_to_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_warehouse: (subData.in_warehouse || 0) / window.conversionAvailability.avgModulesPerTruck,
                            in_transit_to_project: (subData.in_transit_to_project || 0) / window.conversionAvailability.avgModulesPerTruck,
                            delivered: (subData.delivered || 0) / window.conversionAvailability.avgModulesPerTruck,
                        };
                    }
                    if (!source) {
                        while (idx2 < cells.length) { cells[idx2++].textContent = 'N/A'; }
                    } else {
                        cells[idx2++].textContent = formatNumber(source.total_order, 1);
                        cells[idx2++].textContent = formatNumber(source.at_manufacturer || 0, 1);
                        if (data.on_water > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.on_water || 0, 1);
                        if (data.customs_hold > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.customs_hold || 0, 1);
                        if (data.cleared_customs > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.cleared_customs || 0, 1);
                        if (data.in_transit_to_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_transit_to_warehouse || 0, 1);
                        if (data.in_warehouse > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_warehouse || 0, 1);
                        if (data.in_transit_to_project > 0 && cells[idx2]) cells[idx2++].textContent = formatNumber(source.in_transit_to_project || 0, 1);
                        if (cells[idx2]) cells[idx2].textContent = formatNumber(source.delivered || 0, 1);
                    }
                } else {
                    cells[1].textContent = formatNumber(subData.total_order, decimals);
                    cells[2].textContent = formatNumber((subData.at_manufacturer || 0), decimals);
                    let idx = 3;
                    if (data.on_water > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.on_water || 0), decimals); }
                    if (data.customs_hold > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.customs_hold || 0), decimals); }
                    if (data.cleared_customs > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.cleared_customs || 0), decimals); }
                    if (data.in_transit_to_warehouse > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_transit_to_warehouse || 0), decimals); }
                    if (data.in_warehouse > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_warehouse || 0), decimals); }
                    if (data.in_transit_to_project > 0 && cells[idx]) { cells[idx++].textContent = formatNumber((subData.in_transit_to_project || 0), decimals); }
                    if (cells[idx]) cells[idx].textContent = formatNumber(subData.delivered, decimals);
                }
            }
        }
    });

    if (window.updatePieChart) {
        window.updatePieChart(filterType);
    }
}

function formatNumber(num, decimals) {
    return Number(num).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// Admin-only conversion prompt helpers
function openConversionModal(type) {
    const modal = document.getElementById('conversionModal');
    const title = document.getElementById('conversionModalTitle');
    const body = document.getElementById('conversionModalBody');
    if (!modal || !title || !body) return;
    if (type === 'mpp') {
        title.textContent = 'Modules per Pallet not currently listed';
        body.innerHTML = '<label>Modules per Pallet</label>'+
            '<input type="number" id="inputModulesPerPallet" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<p style="margin-top:10px;color:#666;">Temporary for display. Update Module Batch to persist.</p>';
        modal.setAttribute('data-type','mpp');
    } else {
        title.textContent = 'Truckload conversion not currently listed';
        body.innerHTML = '<label>Pallets per Truck (preferred)</label>'+
            '<input type="number" id="inputPalletsPerTruck" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<div style="margin:10px 0;text-align:center;color:#888;">— or —</div>'+
            '<label>Modules per Truck</label>'+
            '<input type="number" id="inputModulesPerTruck" min="1" style="width:100%;padding:8px;margin-top:6px;">'+
            '<p style="margin-top:10px;color:#666;">Temporary for display. Update Module Batch to persist.</p>';
        modal.setAttribute('data-type','truck');
    }
    modal.style.display = 'block';
}
function closeConversionModal(){
    const modal = document.getElementById('conversionModal');
    if (modal) modal.style.display = 'none';
}
function saveConversionModal(){
    const modal = document.getElementById('conversionModal');
    if (!modal) return;
    const type = modal.getAttribute('data-type');
    if (type === 'mpp') {
        const val = parseInt(document.getElementById('inputModulesPerPallet').value || '0', 10);
        if (val > 0) {
            window.conversionAvailability.modulesPerPalletAvailable = true;
            window.conversionAvailability.avgModulesPerPallet = val;
        }
    } else {
        const ppt = parseInt(document.getElementById('inputPalletsPerTruck').value || '0', 10);
        const mpt = parseInt(document.getElementById('inputModulesPerTruck').value || '0', 10);
        if (ppt > 0) {
            window.conversionAvailability.palletsPerTruckAvailable = true;
            window.conversionAvailability.avgPalletsPerTruck = ppt;
        } else if (mpt > 0) {
            window.conversionAvailability.modulesPerTruckAvailable = true;
            window.conversionAvailability.avgModulesPerTruck = mpt;
        }
    }
    closeConversionModal();
    // Re-apply current filter to refresh
    syncFiltersToState();
}

// Customer Shipping Modal functionality
function showCustomerShippingModal(status, onlyKey) {
    const modal = document.getElementById('customerShippingModal');
    const title = document.getElementById('customerShippingModalTitle');
    const content = document.getElementById('customerShippingModalContent');

    if (!modal || !title || !content) {
        console.error('Customer shipping modal elements not found');
        return;
    }

    title.textContent = (onlyKey ? onlyKey : status) + ' - Details';
    content.innerHTML = generateCustomerShippingContent(status, onlyKey);
    modal.style.display = 'block';
}

function closeCustomerShippingModal() {
    const modal = document.getElementById('customerShippingModal');
    if(modal) modal.style.display = 'none';
}

function generateCustomerShippingContent(status, onlyKey) {
    const shippingBreakdown = <?php echo json_encode($detailed_breakdown ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
    let html = '<div>';
    let has = false;
    
    // Handle special case for "Delivered" status
    if(status === 'Delivered') {
        has = true;
        const totalDeliveredRaw = <?php echo (int)($delivered_raw_total ?? 0); ?>;
        const totalPallets = Math.round(totalDeliveredRaw / 30);
        
        // Calculate MWs
        const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        let totalMWs = 0;
        if (wattages.length > 0 && totalDeliveredRaw > 0) {
            const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
            totalMWs = ((totalDeliveredRaw * avgWattage) / 1000000).toFixed(2);
        }
        
        const palletDisplay = totalPallets;
        const moduleDisplay = totalDeliveredRaw.toLocaleString();
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#e8f5e8;border-radius:12px;border-left:4px solid #28a745;">` +
               `<h4 style="margin-top:0;color:#28a745;">🎉 Delivered to Project</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${palletDisplay}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${moduleDisplay}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#28a745;">${totalMWs}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show wattage breakdown
        const deliveredBreakdown = <?php echo json_encode($delivered_by_wattage ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        if(deliveredBreakdown.length > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#28a745;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
            deliveredBreakdown.forEach(function(item) {
                const mws = ((item.modules * item.wattage) / 1000000).toFixed(2);
                let breakdownText = `${item.wattage}W: ${item.pallets} pallets • ${item.modules.toLocaleString()} modules • ${mws} MWs`;
                if(item.damaged_pallets > 0) {
                    breakdownText += ` (${item.damaged_pallets} damaged pallets, ${item.damaged_modules.toLocaleString()} damaged modules)`;
                }
                html += `<li style="padding:8px 0;border-bottom:1px solid #eee;">${breakdownText}</li>`;
            });
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="view_project.php?project_id=<?php echo $project_id; ?>&status_filter=Delivered" class="customer-modal-btn">View Deliveries</a>` +
               `</div>`;
        html += '</div>';
    } else if (status === 'Exceptions') {
        // Handle Exceptions (Damaged Pallets)
        has = true;
        const exceptionsData = {
            damaged: <?php echo ($status_totals['Damaged']['pallets'] ?? 0); ?>,
            damaged_modules: <?php echo ($status_totals['Damaged']['modules'] ?? 0); ?>
        };
        
        const totalExceptionPallets = exceptionsData.damaged;
        const totalExceptionModules = exceptionsData.damaged_modules;
        
        // Calculate MWs
        const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
        let totalMWs = 0;
        if (wattages.length > 0 && totalExceptionModules > 0) {
            const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
            totalMWs = ((totalExceptionModules * avgWattage) / 1000000).toFixed(2);
        }
        
        html += `<div style="margin-bottom:20px;padding:20px;background:#fff3e0;border-radius:12px;border-left:4px solid #f57c00;">` +
               `<h4 style="margin-top:0;color:#e65100;">⚠️ Module Exceptions</h4>` +
               `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:15px 0;">` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalExceptionPallets}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Pallets</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalExceptionModules.toLocaleString()}</div>` +
               `<div style="font-size:0.9rem;color:#666;">Modules</div></div>` +
               `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
               `<div style="font-size:1.8rem;font-weight:700;color:#f57c00;">${totalMWs}</div>` +
               `<div style="font-size:0.9rem;color:#666;">MWs</div></div>` +
               `</div>`;
        
        // Show exception type breakdown
        if (exceptionsData.damaged > 0) {
            html += '<div style="margin-top:20px;"><h5 style="color:#e65100;">Exception Breakdown:</h5><ul style="list-style:none;padding:0;">';
            html += `<li style="padding:12px;margin-bottom:8px;background:#ffebee;border-radius:8px;border-left:3px solid #d32f2f;">` +
                   `<strong>Damaged:</strong> ${exceptionsData.damaged} pallets • ${exceptionsData.damaged_modules.toLocaleString()} modules</li>`;
            html += '</ul></div>';
        }
        
        html += `<div style="text-align:center;margin-top:20px;">` +
               `<a href="warranty.php?project_id=<?php echo $project_id; ?>" class="customer-modal-btn" style="background:#f57c00;border-color:#f57c00;">View Exceptions</a>` +
               `</div>`;
        html += '</div>';
    } else {
        // Handle other shipping statuses
        for(const key in shippingBreakdown){
            if(onlyKey && key !== onlyKey) continue;
            if(key.includes(status)){
                has = true;
                const data = shippingBreakdown[key];
                
                // Calculate MWs and truckloads
                const wattages = <?php echo json_encode($wattages ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]'; ?>;
                let totalMWs = 0;
                if (wattages.length > 0 && data.total_modules > 0) {
                    const avgWattage = wattages.reduce((a, b) => a + b) / wattages.length;
                    totalMWs = ((data.total_modules * avgWattage) / 1000000).toFixed(2);
                }
                const avgPPT = <?php echo ($average_pallets_per_truck !== null && $average_pallets_per_truck > 0) ? (float)$average_pallets_per_truck : 'null'; ?>;
                const truckloads = avgPPT ? (data.pallet_count / avgPPT).toFixed(1) : 'N/A';
                
                html += `<div style="margin-bottom:20px;padding:20px;background:#f8f9fa;border-radius:12px;border-left:4px solid #488C9A;">`+
                       `<h4 style="margin-top:0;color:#488C9A;">${key}</h4>`+
                       `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:15px;margin:15px 0;">` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${data.pallet_count}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Pallets</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${data.total_modules.toLocaleString()}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Modules</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${totalMWs}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">MWs</div></div>` +
                       `<div style="text-align:center;padding:15px;background:white;border-radius:8px;">` +
                       `<div style="font-size:1.5rem;font-weight:700;color:#488C9A;">${truckloads}</div>` +
                       `<div style="font-size:0.8rem;color:#666;">Truckloads</div></div>` +
                       `</div>`;
                
                if(data.wattage_breakdown && Object.keys(data.wattage_breakdown).length>0){
                    html+='<div style="margin-top:20px;"><h5 style="color:#488C9A;">Wattage Breakdown:</h5><ul style="list-style:none;padding:0;">';
                    for(const w in data.wattage_breakdown){
                        const d = data.wattage_breakdown[w];
                        const mws = ((d.modules * parseFloat(w)) / 1000000).toFixed(2);
                        html+=`<li style="padding:8px 0;border-bottom:1px solid #eee;">${w}W: ${d.pallets} pallets • ${d.modules.toLocaleString()} modules • ${mws} MWs</li>`;
                    }
                    html+='</ul></div>';
                }
                
                // Add appropriate action buttons
                if(status === 'At Manufacturer') {
                    html += `<div style="text-align:center;margin-top:20px;">` +
                           `<a href="manage_pallets.php?project_id=<?php echo $project_id; ?>&status_filter=At%20Manufacturer" class="customer-modal-btn">View Pallets</a>` +
                           `</div>`;
                } else if(status === 'In Transit to Warehouse' || status === 'In Transit to Project') {
                    html += `<div style="text-align:center;margin-top:20px;">` +
                           `<a href="view_project.php?project_id=<?php echo $project_id; ?>&status_filter=${encodeURIComponent(status)}" class="customer-modal-btn">View Shipments</a>` +
                           `</div>`;
                } else if(status === 'Customs Hold') {
                    if(data.warehouse_id) {
                        html += `<div style="text-align:center;margin-top:20px;">` +
                               `<a href="warehouse_info.php?warehouse_id=${data.warehouse_id}&project_id=<?php echo $project_id; ?>&tab=customsHold" class="customer-modal-btn" style="background:#dc2626;border-color:#dc2626;">View Customs Hold</a>` +
                               `</div>`;
                    }
                } else if(status === 'In Warehouse') {
                    if(data.warehouse_id) {
                        html += `<div style="text-align:center;margin-top:20px;">` +
                               `<a href="warehouse_info.php?project_id=<?php echo $project_id; ?>&warehouse_id=${data.warehouse_id}" class="customer-modal-btn">View Inventory</a>` +
                               `</div>`;
                    } else {
                        html += `<div style="text-align:center;margin-top:20px;">` +
                               `<a href="warehouse_info.php?project_id=<?php echo $project_id; ?>" class="customer-modal-btn">View Inventory</a>` +
                               `</div>`;
                    }
                }
                html+='</div>';
            }
        }
        
        if(!has){html+='<p style="text-align:center;color:#666;font-style:italic;">No data available.</p>';}
    }
    
    html+='</div>';
    return html;
}

// Initialize customer filters when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeCustomerUnitFilters();
    syncFiltersToState(); // Set initial state
    
    // Initialize timeline remaining text with current filter
    updateTimelineRemainingText(currentFilter);
    
    // Handle large numbers in shipping boxes
    handleLargeNumbers();
});

// Function to detect and handle large numbers in shipping boxes
function handleLargeNumbers() {
    const shippingBoxes = document.querySelectorAll('.shipping-box .status-count, .shipping-box-customer .status-count');
    
    shippingBoxes.forEach(function(countElement) {
        const text = countElement.textContent || countElement.innerText;
        const numericText = text.replace(/[^\d]/g, ''); // Remove non-numeric characters
        
        // If number has 6+ digits (like 204540), mark it as large
        if (numericText.length >= 6) {
            countElement.setAttribute('data-large-number', 'true');
            
            // Also reduce padding on parent container for very large numbers
            const parentBox = countElement.closest('.shipping-box, .shipping-box-customer');
            if (parentBox && numericText.length >= 7) {
                parentBox.style.padding = '15px 8px';
            }
        }
    });
}

<?php if (!empty($enable_overview_financial)): ?>
// Prepare costPie + budgetLineChart (for regular users)
var pieChartDataFinancial = <?php echo json_encode($pieChartDataFinancial ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '[]';?>;
var dateLabelsForBudget   = <?php echo $dateLabelsForBudget ?: '[]';?>;
var budgetLineData        = <?php echo $budgetLineChartDataJSON ?: '{"anticipated_cost":[],"actual_cost":[],"forecast_breakdown":[],"actual_breakdown":[]}';?>;

function initializeFinancialCharts(){
    // Cost Breakdown Pie (optional: only if element exists on page)
    var costPieEl = document.getElementById('costPieChart');
    if (costPieEl && !costPieEl.chartInitialized) {
        var costPie = costPieEl.getContext('2d');
        var costPieLabels = Object.keys(pieChartDataFinancial);
        var costPieValues = Object.values(pieChartDataFinancial);

        var colorMap = {
            'Freight Cost': '#488C9A',
            'Warehousing':   '#293E4C',
            'Accessorial':   '#fbb040',
            'Solterra Fee':  '#5ba3b1',
            'Other':         '#6c757d'
        };
        var backgroundColors = costPieLabels.map(function(lbl){
            return colorMap[lbl] || '#6c757d';
        });

        new Chart(costPie,{
            type:'pie',
            data:{
                labels: costPieLabels,
                datasets:[{
                    data: costPieValues,
                    backgroundColor: backgroundColors
                }]
            },
            options:{
                title:{display:true, text:'Cost Breakdown'},
                tooltips:{
                    callbacks:{
                        label:function(tooltipItem, data){
                            var val=data.datasets[0].data[tooltipItem.index];
                            var lbl=data.labels[tooltipItem.index];
                            return lbl+': $'+ parseFloat(val).toFixed(2);
                        }
                    }
                }
            }
        });
        costPieEl.chartInitialized = true;
    }

    // Forecasted vs Actual cost line chart
    var ctxBudgetEl = document.getElementById('budgetLineChart');
    if (!ctxBudgetEl || ctxBudgetEl.chartInitialized) return; // Exit if element doesn't exist or chart already created
    
    var ctxBudget = ctxBudgetEl.getContext('2d');
    var antCost = (budgetLineData && Array.isArray(budgetLineData.anticipated_cost)) ? budgetLineData.anticipated_cost : [];
    var actCost = (budgetLineData && Array.isArray(budgetLineData.actual_cost)) ? budgetLineData.actual_cost : [];
    var forecastBreakdownData = (budgetLineData && Array.isArray(budgetLineData.forecast_breakdown)) ? budgetLineData.forecast_breakdown : [];
    var actualBreakdownData = (budgetLineData && Array.isArray(budgetLineData.actual_breakdown)) ? budgetLineData.actual_breakdown : [];

    var budgetChart = new Chart(ctxBudget,{
        type:'line',
        data:{
            labels: dateLabelsForBudget,
            datasets:[
                {
                    label:'Forecasted',
                    data: antCost,
                    borderColor:'#488C9A',
                    borderWidth:2,
                    fill:false,
                    borderDash:[5,5],
                    pointRadius:0,
                    pointHoverRadius:6,
                    pointHoverBackgroundColor:'#488C9A',
                    tension:0.1
                },
                {
                    label:'Actual',
                    data: actCost,
                    borderColor:'#293E4C',
                    borderWidth:2,
                    fill:false,
                    pointRadius:0,
                    pointHoverRadius:6,
                    pointHoverBackgroundColor:'#293E4C',
                    spanGaps:false,
                    tension:0.1
                }
            ]
        },
        options:{
            interaction:{ mode:'index', intersect:false },
            responsive:true,
            animation:false,
            scales:{
                x:{
                    type:'time',
                    time:{
                        parser:'yyyy-MM-dd',
                        tooltipFormat:'PP',
                        unit:'month',
                        displayFormats:{month:'MMM yyyy'}
                    },
                    title:{display:true, text:'Date'},
                    ticks:{ maxRotation:70, minRotation:70, autoSkip:true, autoSkipPadding:12 }
                },
                y:{
                    beginAtZero:true,
                    ticks:{ callback:function(val){return '$'+Number(val).toLocaleString();} },
                    title:{display:true, text:'Cost ($)'}
                }
            },
            plugins:{
                tooltip:{
                    backgroundColor:'rgba(41, 62, 76, 0.95)',
                    titleColor:'#fff',
                    bodyColor:'#fff',
                    borderColor:'#488C9A',
                    borderWidth:1,
                    padding:14,
                    displayColors:true,
                    callbacks:{
                        title:function(tooltipItems){
                            if(tooltipItems.length > 0){
                                var date = new Date(tooltipItems[0].parsed.x);
                                return date.toLocaleDateString('en-US', {month:'short', year:'numeric'});
                            }
                            return '';
                        },
                        label:function(context){
                            var label = context.dataset.label || '';
                            var val = context.parsed.y || 0;
                            return label+': $'+ Number(val).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                        },
                        afterBody:function(tooltipItems){
                            if(tooltipItems.length >= 2){
                                var forecasted = tooltipItems[0].parsed.y || 0;
                                var actual = tooltipItems[1].parsed.y;
                                if(actual !== null && actual !== undefined){
                                    var variance = actual - forecasted;
                                    var variancePercent = forecasted > 0 ? (variance / forecasted * 100) : 0;
                                    var sign = variance >= 0 ? '+' : '';
                                    return [
                                        '',
                                        'Variance: ' + sign + '$' + Number(Math.abs(variance)).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) +
                                        ' (' + sign + variancePercent.toFixed(1) + '%)'
                                    ];
                                }
                            }
                            return [];
                        },
                        footer:function(){
                            return 'Click for details';
                        }
                    }
                }
            },
            onClick:function(event, elements){
                if(elements.length > 0){
                    var dataIndex = elements[0].index;
                    var date = dateLabelsForBudget[dataIndex];
                    var forecasted = antCost[dataIndex] || 0;
                    var actual = actCost[dataIndex];

                    // Get cumulative values at this point (already cumulative in the data)
                    var forecastedCumulative = antCost[dataIndex] || 0;
                    var actualCumulative = actCost[dataIndex] || 0;

                    // Get breakdown data for this point
                    var forecastBreakdown = forecastBreakdownData[dataIndex] || null;
                    var actualBreakdown = actualBreakdownData[dataIndex] || null;

                    if(typeof openCostDetailModal === 'function'){
                        openCostDetailModal(date, forecasted, actual, forecastedCumulative, actualCumulative, forecastBreakdown, actualBreakdown);
                    }
                }
            },
            onHover:function(event, elements){
                event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
            }
        }
    });
    ctxBudgetEl.chartInitialized = true;
}
<?php endif; ?>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
// Shipping Filter functionality
function initializeShippingFilters() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    const shippingBoxes = document.querySelectorAll('.shipping-box');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const filterType = this.getAttribute('data-filter');
            
            // Update active button
            filterButtons.forEach(b => {
                b.style.background = 'transparent';
                b.style.color = '#293E4C';
                b.classList.remove('active');
            });
            
            this.style.background = '#488C9A';
            this.style.color = 'white';
            this.classList.add('active');
            
            // Update all shipping boxes
            updateShippingBoxes(filterType);
            
            // Handle large numbers after updating
            setTimeout(handleLargeNumbers, 100);
        });
    });
}

function updateShippingBoxes(filterType) {
    const shippingBoxes = document.querySelectorAll('.shipping-box');
    
    shippingBoxes.forEach(box => {
        const statusCount = box.querySelector('.status-count');
        const statusUnit = box.querySelector('.status-unit');
        
        if (statusCount && statusUnit) {
            let value, unit;
            
            switch(filterType) {
                case 'modules':
                    value = parseInt(box.getAttribute('data-modules') || 0);
                    unit = 'modules';
                    break;
                case 'truckloads':
                    value = parseFloat(box.getAttribute('data-truckloads') || 0);
                    unit = 'truckloads';
                    break;
                case 'mws':
                    value = parseFloat(box.getAttribute('data-mws') || 0);
                    unit = 'MWs';
                    break;
                case 'pallets':
                default:
                    value = parseInt(box.getAttribute('data-pallets') || 0);
                    unit = 'pallets';
                    break;
            }
            
            // Format the value based on type
            let displayText;
            if (isNaN(value)) {
                displayText = 'N/A';
            } else if (filterType === 'truckloads' || filterType === 'mws') {
                const decimals = filterType === 'mws' ? 2 : 1;
                displayText = value % 1 === 0 ? value.toString() : value.toFixed(decimals);
            } else {
                displayText = Math.round(value).toLocaleString();
            }

            statusCount.textContent = displayText;
            statusUnit.textContent = unit;

            // Add class for large numbers to shrink font
            statusCount.classList.remove('large-number', 'very-large-number');
            if (displayText.length >= 7) {
                statusCount.classList.add('very-large-number');
            } else if (displayText.length >= 5) {
                statusCount.classList.add('large-number');
            }
        }
    });
}

// Initialize shipping filters when page loads
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.shipping-filters')) {
        initializeShippingFilters();
    }
    
    // Initialize admin unit filters and timeline remaining text
    initializeAdminUnitFilters();
    updateTimelineRemainingTextAdmin();
    
    // Initialize shipping boxes with default filter (MWs)
    updateShippingBoxes('mws');
});

// Global filter state for admin
let currentAdminFilter = 'mws';

// Initialize admin unit filters functionality
function initializeAdminUnitFilters() {
    const filterSections = document.querySelectorAll('.unit-filters');
    
    filterSections.forEach(section => {
        const filterButtons = section.querySelectorAll('.unit-filter-btn');
        
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                const filterType = this.getAttribute('data-unit');
                
                // Update global filter state
                currentAdminFilter = filterType;
                
                // Update active button states in all filter sections
                filterSections.forEach(fs => {
                    fs.querySelectorAll('.unit-filter-btn').forEach(b => {
                        b.classList.remove('active');
                        if (b.getAttribute('data-unit') === filterType) {
                            b.classList.add('active');
                        }
                    });
                });
                
                // Update timeline remaining text
                updateTimelineRemainingTextAdmin();
                
                // Update admin shipping boxes
                updateShippingBoxes(filterType);
                
                // Handle large numbers after updating
                setTimeout(handleLargeNumbers, 100);
            });
        });
    });
}

// Admin version of timeline remaining text update (with filters)
function updateTimelineRemainingTextAdmin() {
    const timelineTexts = document.querySelectorAll('.timeline-remaining-text');
    if (!timelineTexts.length) return;
    
    // Check if project is completed
    const isCompleted = <?php echo $step5_completed ? 'true' : 'false'; ?>;
    if (isCompleted) return; // Don't update if project is already completed
    
    // Get project data for calculations  
    const totalModules = <?php echo $total_raw_modules; ?>;
    const deliveredModules = <?php echo $delivered_raw_total; ?>;
    const projectSizeMW = <?php echo number_format($project_size_mw, 2); ?>;
    
    // Calculate delivered MW (approximate based on delivered/total ratio)
    const deliveryRatio = totalModules > 0 ? (deliveredModules / totalModules) : 0;
    const deliveredMW = projectSizeMW * deliveryRatio;
    
    // Calculate totals and remaining for each filter type
    let remaining, unit;
    
    switch(currentAdminFilter) {
        case 'modules':
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
            break;
            
        case 'mws':
            remaining = projectSizeMW - deliveredMW;
            unit = 'MWs';
            remaining = Math.max(0, parseFloat(remaining.toFixed(2)));
            break;
            
        case 'pallets':
            // Use actual pallet counts from the database
            const actualPalletData = <?php echo json_encode($pallets_status_main ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS) ?: '{}'; ?>;
            const totalActualPallets = actualPalletData.total_order;
            const deliveredActualPallets = actualPalletData.delivered;
            remaining = Math.max(0, totalActualPallets - deliveredActualPallets);
            unit = 'pallets';
            break;
            
        case 'truckloads':
            // Estimate truckloads based on modules (using average modules per truck if available)  
            const avgModulesPerTruck = <?php echo ($weighted_avg_modules_per_truck !== null && $weighted_avg_modules_per_truck > 0) ? (int)$weighted_avg_modules_per_truck : 500; ?>;
            const totalTrucks = Math.ceil(totalModules / avgModulesPerTruck);
            const deliveredTrucks = Math.floor(deliveredModules / avgModulesPerTruck);
            remaining = Math.max(0, totalTrucks - deliveredTrucks);
            unit = 'truckloads';
            break;
            
        default:
            remaining = Math.max(0, totalModules - deliveredModules);
            unit = 'modules';
    }
    
    // Update all timeline remaining text elements
    timelineTexts.forEach(element => {
        const countSpan = element.querySelector('.remaining-count');
        const unitSpan = element.querySelector('.remaining-unit');
        
        if (countSpan && unitSpan) {
            countSpan.textContent = remaining.toLocaleString('en-US');
            unitSpan.textContent = unit;
        }
    });
}

<?php endif; ?>

// Dropdown functionality
function toggleCustomerModulesDropdown() {
    var dropdown = document.getElementById("customerModulesDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:first-child .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerDeliveriesDropdown() {
    var dropdown = document.getElementById("customerDeliveriesDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(2) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerReportsDropdown() {
    var dropdown = document.getElementById("customerReportsDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(4) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleCustomerDocumentsDropdown() {
    var dropdown = document.getElementById("customerDocumentsDropdown");
    var dropdownBtn = document.querySelector("#customer-buttons .dropdown:nth-child(5) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleModulesDropdown() {
    var dropdown = document.getElementById("modulesDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:first-child .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleAdminDeliveriesDropdown() {
    var dropdown = document.getElementById("adminDeliveriesDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:nth-child(2) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleAdminDocumentsDropdown() {
    var dropdown = document.getElementById("adminDocumentsDropdown");
    var dropdownBtn = document.querySelector("#admin-buttons .dropdown:nth-child(3) .dropdown-btn");
    
    dropdown.classList.toggle("show");
    dropdownBtn.classList.toggle("active");
}

function toggleMainDeliveriesDropdown() {
    // This function handles the main deliveries dropdown if it exists
    var dropdown = document.getElementById("mainDeliveriesDropdown");
    if (dropdown) {
        dropdown.classList.toggle("show");
    }
}

// Order Breakdown Modal
function openOrderBreakdownModal() {
    const modal = document.getElementById('orderBreakdownModal');
    if (modal) modal.classList.add('show');
}

function closeOrderBreakdownModal() {
    const modal = document.getElementById('orderBreakdownModal');
    if (modal) modal.classList.remove('show');
}

// Domestic Content Modal
function openDomesticContentModal() {
    const modal = document.getElementById('dcModal');
    if (modal) modal.classList.add('show');
}

function closeDomesticContentModal() {
    const modal = document.getElementById('dcModal');
    if (modal) modal.classList.remove('show');
}

// Close modals on overlay click
document.addEventListener('click', function(event) {
    if (event.target.classList.contains('dc-modal-overlay')) {
        event.target.classList.remove('show');
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.dc-modal-overlay.show').forEach(function(m) {
            m.classList.remove('show');
        });
    }
});

// Project Actions Functions (settings gear dropdown)
function toggleProjectActions() {
    const dropdown = document.getElementById('projectActionsDropdown');
    if (!dropdown) return;
    dropdown.style.display = '';
    const isOpen = dropdown.classList.contains('show');
    // Close other dropdowns
    document.querySelectorAll('.project-settings-dropdown').forEach(d => d.classList.remove('show'));
    dropdown.classList.toggle('show', !isOpen);
}

// ==================== HEALTH MODAL FUNCTIONS ====================
function openHealthModal() {
    const modal = document.getElementById('healthModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        // Update character count
        updateHealthCharCount();
        // Check if reason should be shown
        const selected = document.querySelector('input[name="health_status"]:checked');
        if (selected) {
            toggleHealthReason(selected.value);
        }
    }
}

function closeHealthModal() {
    const modal = document.getElementById('healthModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

function selectHealthOption(value) {
    // Update visual selection
    document.querySelectorAll('.health-option').forEach(opt => opt.classList.remove('selected'));
    const radio = document.querySelector(`input[name="health_status"][value="${value}"]`);
    if (radio) {
        radio.checked = true;
        radio.closest('.health-option').classList.add('selected');
    }
    toggleHealthReason(value);
}

function toggleHealthReason(value) {
    const container = document.getElementById('healthReasonContainer');
    const textarea = document.getElementById('healthReason');
    const saveBtn = document.getElementById('healthSaveBtn');

    if (value === 'at_risk' || value === 'behind') {
        container.classList.add('show');
        textarea.required = true;
        // Validate save button state
        validateHealthForm();
    } else {
        container.classList.remove('show');
        textarea.required = false;
        if (saveBtn) saveBtn.disabled = false;
    }
}

function validateHealthForm() {
    const selected = document.querySelector('input[name="health_status"]:checked');
    const textarea = document.getElementById('healthReason');
    const saveBtn = document.getElementById('healthSaveBtn');

    if (!selected || !saveBtn) return;

    if ((selected.value === 'at_risk' || selected.value === 'behind') && textarea.value.trim().length === 0) {
        saveBtn.disabled = true;
    } else {
        saveBtn.disabled = false;
    }
}

function updateHealthCharCount() {
    const textarea = document.getElementById('healthReason');
    const charCount = document.getElementById('charCount');
    if (textarea && charCount) {
        charCount.textContent = textarea.value.length;
    }
}

// Health modal event listeners
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('healthReason');
    if (textarea) {
        textarea.addEventListener('input', function() {
            updateHealthCharCount();
            validateHealthForm();
        });
    }

    // Close modal on backdrop click
    const modal = document.getElementById('healthModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeHealthModal();
            }
        });
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeHealthModal();
        }
    });

    // Health option radio change handler
    document.querySelectorAll('input[name="health_status"]').forEach(radio => {
        radio.addEventListener('change', function() {
            selectHealthOption(this.value);
        });
    });
});

// Close project settings dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.project-header-actions') && !event.target.closest('.project-settings-btn')) {
        document.querySelectorAll('.project-settings-dropdown').forEach(d => d.classList.remove('show'));
    }
});

<?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>

// Delete Project Functions
let deleteProjectId = null;
let deleteProjectName = null;

function confirmDeleteProject(projectId, projectName) {
    deleteProjectId = projectId;
    deleteProjectName = projectName;
    document.getElementById('deleteModalText').innerHTML = 
        `Are you sure you want to delete the project "<strong>${projectName}</strong>"?<br><br>
        This will permanently delete:<br>
        • All module batches and pallets<br>
        • All deliveries and shipments<br>
        • All project data and documents<br><br>
        <strong>This action cannot be undone.</strong>`;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
    deleteProjectId = null;
    deleteProjectName = null;
}

function confirmDelete() {
    if (!deleteProjectId) return;
    
    // Show loading state
    const deleteBtn = document.querySelector('.btn-delete');
    deleteBtn.disabled = true;
    deleteBtn.textContent = 'Deleting...';
    
    // Send delete request
    fetch('delete_project_cascade.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'project_id=' + encodeURIComponent(deleteProjectId) + '&csrf_token=' + encodeURIComponent(<?php echo json_encode($_SESSION['csrf_token']); ?>)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Project "' + deleteProjectName + '" deleted successfully.');
            window.location.href = 'manage_projects.php';
        } else {
            alert('Error deleting project: ' + (data.error || 'Unknown error'));
            deleteBtn.disabled = false;
            deleteBtn.textContent = 'Delete Project';
        }
    })
    .catch(error => {
        alert('Error deleting project: ' + error.message);
        deleteBtn.disabled = false;
        deleteBtn.textContent = 'Delete Project';
    });
}

// Add Module Modal Functions
function openAddModuleModal() {
    // Load manufacturers
    loadManufacturers();
    // Clear form
    document.getElementById('addModuleForm').reset();
    document.getElementById('modal_wattage_container').innerHTML = '';
    addModalWattageField(); // Add one default wattage field
    document.getElementById('addModuleModal').style.display = 'block';
}

function closeAddModuleModal() {
    document.getElementById('addModuleModal').style.display = 'none';
}

function loadManufacturers() {
    // Populate manufacturer dropdown (you'll need to implement this based on your data)
    const select = document.getElementById('modal_manufacturer_id');
    select.innerHTML = '<option value="">Select Manufacturer</option>';
    
    // Add manufacturers from PHP data or make AJAX call
    <?php if (!empty($manufacturers)): ?>
    <?php foreach ($manufacturers as $mfg): ?>
    const option<?php echo $mfg['id']; ?> = document.createElement('option');
    option<?php echo $mfg['id']; ?>.value = '<?php echo $mfg['id']; ?>';
    option<?php echo $mfg['id']; ?>.textContent = '<?php echo htmlspecialchars($mfg['name'], ENT_QUOTES); ?>';
    select.appendChild(option<?php echo $mfg['id']; ?>);
    <?php endforeach; ?>
    <?php endif; ?>
}

let modalWattageIndex = 0;
function addModalWattageField() {
    const container = document.getElementById('modal_wattage_container');
    const div = document.createElement('div');
    div.className = 'wattage-entry';
    div.innerHTML = `
        <div class="modal-form-group">
            <label>Wattage (W):</label>
            <input type="number" name="wattages[${modalWattageIndex}]" step="1" min="1" required placeholder="e.g., 555">
        </div>
        <div class="modal-form-group">
            <label>Quantity:</label>
            <input type="number" name="quantities[${modalWattageIndex}]" step="1" min="1" required placeholder="e.g., 1000">
        </div>
        <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
    `;
    container.appendChild(div);
    modalWattageIndex++;
}

// Edit Batch Modal Functions
function openEditBatchModal(batchId, batchName) {
    document.getElementById('editBatchModalTitle').textContent = 'Edit Module Batch: ' + batchName;
    document.getElementById('edit_batch_id').value = batchId;
    
    // Load existing wattage data for this batch
    loadBatchWattages(batchId);
    
    document.getElementById('editBatchModal').style.display = 'block';
}

function closeEditBatchModal() {
    document.getElementById('editBatchModal').style.display = 'none';
}

function loadBatchWattages(batchId) {
    // Make AJAX call to get current wattages for this batch
    fetch('get_batch_wattages.php?batch_id=' + batchId)
    .then(response => response.json())
    .then(data => {
        const container = document.getElementById('edit_wattage_container');
        container.innerHTML = '';
        
        if (data.success && data.wattages) {
            data.wattages.forEach(function(wattage, index) {
                addEditWattageField(wattage.wattage, wattage.quantity, wattage.id);
            });
        }
        
        if (container.children.length === 0) {
            addEditWattageField(); // Add empty field if no existing data
        }
    })
    .catch(error => {
        console.error('Error loading batch wattages:', error);
        addEditWattageField(); // Add empty field on error
    });
}

let editWattageIndex = 0;
function addEditWattageField(wattage = '', quantity = '', itemId = '') {
    const container = document.getElementById('edit_wattage_container');
    const div = document.createElement('div');
    div.className = 'wattage-entry';
    div.innerHTML = `
        <div class="modal-form-group">
            <label>Wattage (W):</label>
            <input type="number" name="wattages[${editWattageIndex}]" step="1" min="1" required value="${wattage}" placeholder="e.g., 555">
            <input type="hidden" name="item_ids[${editWattageIndex}]" value="${itemId}">
        </div>
        <div class="modal-form-group">
            <label>Quantity:</label>
            <input type="number" name="quantities[${editWattageIndex}]" step="1" min="1" required value="${quantity}" placeholder="e.g., 1000">
        </div>
        <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
    `;
    container.appendChild(div);
    editWattageIndex++;
}

// Form submission handlers
const addModuleForm = document.getElementById('addModuleForm');
if (addModuleForm) {
    addModuleForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('project_id', <?php echo $project_id; ?>);
        formData.append('action', 'add_module_batch');

        fetch('handle_module_batch.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Module batch added successfully!');
                location.reload();
            } else {
                alert('Error adding module batch: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            alert('Error adding module batch: ' + error.message);
        });
    });
}

const editBatchForm = document.getElementById('editBatchForm');
if (editBatchForm) {
    editBatchForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'edit_module_batch');
    
    fetch('handle_module_batch.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Module batch updated successfully!');
            location.reload();
        } else {
            alert('Error updating module batch: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        alert('Error updating module batch: ' + error.message);
    });
    });
}
<?php endif; ?>
</script>

<?php include 'components/project_overview/modals.php'; ?>

</body>
</html>
