<?php
/**
 * Project Overview Views Component - Unified Version
 * Contains unified views for all roles with role-based buttons and permissions
 *
 * Structure:
 * - Main Tabs: Project Overview | Analytics
 * - Project Overview Sub-tabs: Timeline | Site | Modules
 * - Analytics Sub-tabs: Deliveries | Financial
 * - Unit filters only on Timeline and Deliveries sub-tabs
 *
 * Required variables from data_processing.php:
 * - $role, $isAdmin, $isGlobalAdmin: User role information
 * - $project_id, $project: Project data
 * - $deliveriesLink: Link to deliveries page based on role
 * - All step completion variables, status totals, module data, etc.
 */
?>

<!-- Unified Button Group -->
<div class="button-group unified-buttons" style="display: flex;">
    <!-- Modules Dropdown -->
    <div class="dropdown">
        <button class="dropdown-btn" onclick="toggleDropdown('modulesDropdown')">
            Modules <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-content" id="modulesDropdown">
            <a href="module_overview.php?project_id=<?php echo $project_id; ?>">
                <?php echo $isAdmin ? '' : '📦 '; ?>Module Overview
            </a>
            <a href="<?php echo $isAdmin ? 'create_shipment.php' : 'manage_pallets.php'; ?>?project_id=<?php echo $project_id; ?>">
                <?php echo $isAdmin ? 'Manage Pallets' : '📋 View Pallets'; ?>
            </a>
            <a href="module_movements.php?project_id=<?php echo $project_id; ?>">
                <?php echo $isAdmin ? '' : '📍 '; ?>Module Movements
            </a>
        </div>
    </div>

    <!-- Deliveries Dropdown -->
    <div class="dropdown">
        <button class="dropdown-btn" onclick="toggleDropdown('deliveriesDropdown')">
            Deliveries <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-content" id="deliveriesDropdown">
            <?php if ($isAdmin): ?>
                <a href="create_shipment.php?project_id=<?php echo $project_id; ?>">Create Shipments</a>
                <a href="manage_deliveries.php?project_id=<?php echo $project_id; ?>">Manage Deliveries</a>
                <a href="scheduling.php?project_id=<?php echo $project_id; ?>">Scheduling</a>
            <?php else: ?>
                <a href="<?php echo $deliveriesLink; ?>">📋 Delivery Schedule</a>
            <?php endif; ?>
            <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>">
                <?php echo $isAdmin ? 'Anticipated Schedule' : '📅 Anticipated Schedule'; ?>
            </a>
        </div>
    </div>

    <!-- Warehousing Button -->
    <button onclick="<?php echo $isAdmin ? 'handleAdminWarehousing()' : "window.location.href='warehouse_info?project_id={$project_id}'"; ?>">
        Warehousing
    </button>

    <?php if (!$isAdmin): ?>
    <!-- Reports Dropdown (Non-Admin Only) -->
    <div class="dropdown">
        <button class="dropdown-btn" onclick="toggleDropdown('reportsDropdown')">
            Reports <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-content" id="reportsDropdown">
            <a href="project_cost_details?project_id=<?php echo $project_id; ?>">💰 Cost Report</a>
            <a href="project_sustainability_details?project_id=<?php echo $project_id; ?>">🌱 Sustainability Report</a>
            <a href="warranty.php?project_id=<?php echo $project_id; ?>">⚠️ Exceptions</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <!-- Exceptions Button (Admin Only) -->
    <button onclick="window.location.href='warranty.php?project_id=<?php echo $project_id; ?>'">
        Exceptions
    </button>
    <?php endif; ?>

    <!-- Documents Dropdown -->
    <div class="dropdown">
        <button class="dropdown-btn" onclick="toggleDropdown('documentsDropdown')">
            Documents <span class="dropdown-arrow">▼</span>
        </button>
        <div class="dropdown-content" id="documentsDropdown">
            <a href="project_documents?project_id=<?php echo $project_id; ?>">📁 Project Documents</a>
            <a href="global_documents.php?project_id=<?php echo $project_id; ?>&from=project_overview">🌐 Global Documents</a>
        </div>
    </div>
</div>
</div>
</div>

<!-- Main Tabs Navigation -->
<div class="main-tabs-container">
    <div class="main-tabs">
        <button class="main-tab-btn active" data-tab="project-overview" onclick="switchMainTab('project-overview')">
            Project Overview
        </button>
        <button class="main-tab-btn" data-tab="analytics" onclick="switchMainTab('analytics')">
            Analytics
        </button>
    </div>
</div>

<!-- ==================== PROJECT OVERVIEW TAB ==================== -->
<div id="project-overview-tab" class="main-tab-content active">

    <!-- Sub-tabs for Project Overview -->
    <div class="sub-tabs-container">
        <div class="sub-tabs">
            <button class="sub-tab-btn active" data-subtab="timeline" onclick="switchSubTab('project-overview', 'timeline')">
                Timeline
            </button>
            <button class="sub-tab-btn" data-subtab="site" onclick="switchSubTab('project-overview', 'site')">
                Site
            </button>
            <button class="sub-tab-btn" data-subtab="modules" onclick="switchSubTab('project-overview', 'modules')">
                Modules
            </button>
        </div>
    </div>

    <!-- ===== TIMELINE SUB-TAB ===== -->
    <div id="subtab-timeline" class="sub-tab-content active">
        <!-- Unit Filters (only shown on Timeline) -->
        <div class="unit-filters-container">
            <div class="unit-filters">
                <button type="button" class="unit-filter-btn active" data-unit="mws">MWs</button>
                <button type="button" class="unit-filter-btn" data-unit="modules">Modules</button>
                <button type="button" class="unit-filter-btn" data-unit="pallets">Pallets</button>
                <button type="button" class="unit-filter-btn" data-unit="truckloads">Truckloads</button>
            </div>
        </div>

        <div class="timeline-container">
            <ul class="timeline" style="--progress-width: <?php echo $progress_percentage; ?>%">

                <!-- Step 1: Project Created -->
                <li class="timeline-item<?php echo $step1_completed ? ' completed' : ''; ?><?php echo $current_step == 1 ? ' current' : ''; ?>">
                    <?php
                    $step1_url = $isAdmin ? "edit_project.php?project_id={$project_id}" : "project_information.php?project_id={$project_id}";
                    ?>
                    <div class="circle clickable" onclick="window.location.href='<?php echo $step1_url; ?>'">1</div>
                    <span class="label">
                        <a href="<?php echo $step1_url; ?>">Project Created</a>
                    </span>
                    <div class="description">Foundation established</div>
                </li>

                <!-- Step 2: Add Modules -->
                <li class="timeline-item<?php echo $step2_completed ? ' completed' : ''; ?><?php echo $current_step == 2 ? ' current' : ''; ?>">
                    <?php if ($isGlobalAdmin): ?>
                        <div class="circle clickable" onclick="window.location.href='add_module_batch.php?project_id=<?php echo $project_id; ?>'">2</div>
                        <span class="label">
                            <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">Add Modules</a>
                        </span>
                    <?php elseif ($step2_completed): ?>
                        <div class="circle clickable" onclick="window.location.href='module_overview.php?project_id=<?php echo $project_id; ?>'">2</div>
                        <span class="label">
                            <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Modules Added</a>
                        </span>
                    <?php else: ?>
                        <div class="circle">2</div>
                        <span class="label">Add Modules</span>
                    <?php endif; ?>
                    <div class="description">
                        <?php echo number_format($total_raw_modules); ?> modules added
                        <?php if ($ordered_mw > 0 && $project_size_mw > 0):
                            $mw_diff = $ordered_mw - $project_size_mw;
                            $mw_percentage = round(($ordered_mw / $project_size_mw) * 100);
                        ?>
                        <div style="font-size: 10px; color: #488C9A; margin-top: 2px;">
                            (<?php echo number_format($ordered_mw, 2); ?> / <?php echo number_format($project_size_mw, 2); ?> MW)
                        </div>
                        <?php if ($mw_diff < 0): ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(255, 193, 7, 0.12); border-radius: 3px; color: #856404;">
                            <?php echo number_format(abs($mw_diff), 2); ?> MW short of target
                        </div>
                        <?php elseif ($mw_diff > 0): ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(40, 167, 69, 0.12); border-radius: 3px; color: #155724;">
                            +<?php echo number_format($mw_diff, 2); ?> MW over target
                        </div>
                        <?php else: ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(40, 167, 69, 0.12); border-radius: 3px; color: #155724;">
                            Target reached
                        </div>
                        <?php endif; ?>
                        <?php elseif ($ordered_mw > 0): ?>
                        <div style="font-size: 10px; color: #488C9A; margin-top: 2px;">(<?php echo number_format($ordered_mw, 2); ?> MW)</div>
                        <?php endif; ?>
                    </div>
                </li>

                <!-- Step 3: Palletize Modules -->
                <li class="timeline-item<?php echo $step3_completed ? ' completed' : ''; ?><?php echo $current_step == 3 ? ' current' : ''; ?>">
                    <?php
                    $step3_clickable = $isAdmin || $step3_completed;
                    $step3_url = $isAdmin ? "module_overview.php?project_id={$project_id}" : "manage_pallets.php?project_id={$project_id}";
                    ?>
                    <div class="circle<?php echo $step3_clickable ? ' clickable' : ''; ?>"
                         <?php echo $step3_clickable ? "onclick=\"window.location.href='{$step3_url}'\"" : ''; ?>>3</div>
                    <span class="label">
                        <?php if ($step3_clickable): ?>
                            <a href="<?php echo $step3_url; ?>">
                                <?php echo $isAdmin ? 'Palletize Modules' : 'Modules Palletized'; ?>
                            </a>
                        <?php else: ?>
                            Modules Palletized
                        <?php endif; ?>
                    </span>
                    <div class="description">
                        <?php echo number_format($actual_palletized_count) . ' of ' . number_format($expected_pallets) . ' palletized'; ?>
                    </div>
                </li>

                <!-- Step 4: Shipping -->
                <li class="timeline-item<?php echo $step4_completed ? ' completed' : ''; ?><?php echo $current_step == 4 ? ' current' : ''; ?>">
                    <div class="circle clickable" onclick="window.location.href='<?php echo $deliveriesLink; ?>'">4</div>
                    <span class="label">
                        <a href="<?php echo $deliveriesLink; ?>">Shipping</a>
                    </span>
                    <div class="description">Logistics in progress</div>

                    <?php if ($current_step >= 4): ?>
                    <div class="shipping-connector"></div>
                    <div class="shipping-boxes-container">
                        <?php
                        $shipping_statuses_list = [
                            ["key" => "At Manufacturer", "class" => "at-manufacturer", "label" => "At Manufacturer"],
                            ["key" => "On Water", "class" => "on-water", "label" => "On Water"],
                            ["key" => "Cleared Customs", "class" => "cleared-customs", "label" => "Cleared Customs"],
                            ["key" => "In Transit to Warehouse", "class" => "in-transit-warehouse", "label" => "In Transit to Warehouse"],
                            ["key" => "In Warehouse", "class" => "in-warehouse", "label" => "In Warehouse"],
                            ["key" => "In Transit to Project", "class" => "in-transit-project", "label" => "In Transit to Project"],
                            ["key" => "Delivered", "class" => "delivered", "label" => "Delivered"],
                        ];

                        foreach ($shipping_statuses_list as $status_info):
                            $status_key = $status_info["key"];
                            $pallets = $status_totals[$status_key]["pallets"] ?? 0;
                            $modules = $status_totals[$status_key]["modules"] ?? 0;

                            if ($pallets > 0):
                                $truckloads = null;
                                if (!empty($average_pallets_per_truck)) {
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                } elseif (!empty($average_modules_per_truck)) {
                                    $truckloads = round($modules / $average_modules_per_truck, 1);
                                }
                                $mws = 0;
                                if (!empty($wattages) && $modules > 0) {
                                    $total_watts = array_sum($wattages);
                                    $avg_wattage = count($wattages) > 0 ? ($total_watts / count($wattages)) : 0;
                                    $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                }
                                // Use unified class but with role-based click handler
                                $clickHandler = $isAdmin ? "showShippingBreakdown('{$status_key}')" : "showCustomerShippingModal('{$status_key}')";
                        ?>
                        <div class="shipping-box <?php echo $status_info["class"]; ?>"
                             onclick="<?php echo $clickHandler; ?>"
                             data-pallets="<?php echo $pallets; ?>"
                             data-modules="<?php echo $modules; ?>"
                             data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                             data-mws="<?php echo $mws; ?>">
                            <div class="status-label"><?php echo $status_info["label"]; ?></div>
                            <div class="status-count"><?php echo $mws; ?></div>
                            <div class="status-unit">MWs</div>
                        </div>
                        <?php
                            endif;
                        endforeach;

                        // Exceptions
                        $exceptions_pallets = ($status_totals["Damaged"]["pallets"] ?? 0);
                        $exceptions_modules = ($status_totals["Damaged"]["modules"] ?? 0);
                        if ($exceptions_pallets > 0):
                            $truckloads = null;
                            if (!empty($average_pallets_per_truck)) {
                                $truckloads = round($exceptions_pallets / $average_pallets_per_truck, 1);
                            }
                            $mws = 0;
                            if (!empty($wattages) && $exceptions_modules > 0) {
                                $total_watts = array_sum($wattages);
                                $avg_wattage = count($wattages) > 0 ? ($total_watts / count($wattages)) : 0;
                                $mws = round(($exceptions_modules * $avg_wattage) / 1000000, 2);
                            }
                            $clickHandler = $isAdmin ? "showShippingBreakdown('Exceptions')" : "showCustomerShippingModal('Exceptions')";
                        ?>
                        <div class="shipping-box exceptions"
                             onclick="<?php echo $clickHandler; ?>"
                             data-pallets="<?php echo $exceptions_pallets; ?>"
                             data-modules="<?php echo $exceptions_modules; ?>"
                             data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                             data-mws="<?php echo $mws; ?>">
                            <div class="status-label">Exceptions</div>
                            <div class="status-count"><?php echo $mws; ?></div>
                            <div class="status-unit">MWs</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </li>

                <!-- Step 5: Project Completion -->
                <li class="timeline-item timeline-progress-step<?php echo $step5_completed ? ' completed' : ''; ?><?php echo $current_step == 5 ? ' current' : ''; ?>">
                    <!-- SVG Gradient Definition -->
                    <svg style="position: absolute; width: 0; height: 0;">
                        <defs>
                            <linearGradient id="completionGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                            </linearGradient>
                            <linearGradient id="timelineProgressGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                            </linearGradient>
                        </defs>
                    </svg>

                    <div class="completion-hub">
                        <div class="completion-hub-inner">
                            <span class="percentage"><?php echo $project_completion_percentage; ?>%</span>
                            <span class="percentage-label">Complete</span>
                        </div>
                        <svg class="progress-ring" viewBox="0 0 100 100">
                            <circle class="progress-ring-track" cx="50" cy="50" r="45"></circle>
                            <circle class="progress-ring-fill" cx="50" cy="50" r="45" style="--progress: <?php echo $project_completion_percentage; ?>"></circle>
                        </svg>
                    </div>

                    <span class="label">
                        <?php if ($step5_completed): ?>
                            Project Completed
                        <?php else: ?>
                            Project Completion
                        <?php endif; ?>
                    </span>
                    <div class="description timeline-progress-details">
                        <?php
                        if ($step5_completed) {
                            echo "All modules delivered and project finalized";
                        } else {
                            $remaining_mw = $project_size_mw - $delivered_mw;
                            echo '<div style="font-size: 11px; line-height: 1.5;">';
                            echo '<div>' . number_format($delivered_mw, 2) . ' / ' . number_format($project_size_mw, 2) . ' MW delivered</div>';
                            if ($ordered_mw > 0 && $ordered_mw < $project_size_mw) {
                                echo '<div style="color: #856404;">(' . number_format($ordered_mw, 2) . ' MW ordered, ' . $ordered_vs_target_percentage . '% of target)</div>';
                            } elseif ($ordered_mw >= $project_size_mw) {
                                echo '<div style="color: #155724;">(' . $delivered_percentage . '% of ordered delivered)</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                </li>
            </ul>
        </div>
    </div>

    <!-- ===== SITE SUB-TAB ===== -->
    <div id="subtab-site" class="sub-tab-content" style="display:none;">
        <div class="info-container">
            <div class="header-with-button">
                <h2>Site Information</h2>
                <?php if ($isGlobalAdmin): ?>
                    <button onclick="window.location.href='edit_project.php?project_id=<?php echo $project_id; ?>'" class="info-action-button" style="margin: 0;">
                        Edit Site Information
                    </button>
                <?php endif; ?>
            </div>
            <div class="info-grid">
                <div class="info-section">
                    <h3>Project Details</h3>
                    <div class="info-item">
                        <label>Project Name:</label>
                        <span><?php echo htmlspecialchars($project['project_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Project Address:</label>
                        <span><?php echo htmlspecialchars($project['project_address'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Project Size:</label>
                        <span><?php echo number_format($project_size_mw, 2); ?> MW</span>
                    </div>
                </div>
                <div class="info-section">
                    <h3>Contact Information</h3>
                    <div class="info-item">
                        <label>Site Contact:</label>
                        <span><?php echo htmlspecialchars($project['site_contact_name'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Contact Email:</label>
                        <span><?php echo htmlspecialchars($project['site_contact_email'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Contact Phone:</label>
                        <span><?php echo htmlspecialchars($project['site_contact_phone'] ?? 'Not specified'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== MODULES SUB-TAB ===== -->
    <div id="subtab-modules" class="sub-tab-content" style="display:none;">
        <div class="info-container">
            <div class="header-with-button">
                <h2>Module Information</h2>
                <?php if ($isGlobalAdmin): ?>
                <div class="dropdown" style="display:inline-block;">
                    <button class="dropdown-btn info-action-button" onclick="toggleDropdown('moduleActionsDropdown')" style="margin:0; padding:10px 16px;">
                        Actions <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="moduleActionsDropdown" style="right:0;left:auto;">
                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">+ Add New Module Batch</a>
                        <?php foreach ($module_batches as $batch): ?>
                            <a href="edit_module_batch.php?batch_id=<?php echo $batch['id']; ?>">Edit: <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Batch #'.$batch['id']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($module_batches)): ?>
                <?php foreach ($module_batches as $batch): ?>
                    <div class="module-batch-section">
                        <h3 class="batch-title">
                            <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch'); ?>
                            <?php if (!empty($batch['is_replacement_batch'])): ?>
                                <span class="batch-badge">Replacement</span>
                            <?php endif; ?>
                        </h3>

                        <div class="info-grid">
                            <div class="info-section">
                                <h3>Basic Information</h3>
                                <div class="info-item">
                                    <label>Account:</label>
                                    <span><?php echo htmlspecialchars($batch['account_name'] ?? 'N/A'); ?></span>
                                </div>
                                <?php if (!empty($batch['wattages'])): ?>
                                    <div class="info-item">
                                        <label>Wattages:</label>
                                        <span>
                                            <?php
                                            $wattage_labels = array_map(function($w) {
                                                return $w['wattage'] . 'W (' . number_format($w['quantity']) . ')';
                                            }, $batch['wattages']);
                                            echo implode(', ', $wattage_labels);
                                            ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php
                                // Calculate total modules for this batch
                                $batch_total_modules = 0;
                                if (!empty($batch['wattages'])) {
                                    foreach ($batch['wattages'] as $w) {
                                        $batch_total_modules += $w['quantity'];
                                    }
                                }
                                ?>
                                <div class="info-item">
                                    <label>Total Modules:</label>
                                    <span><?php echo number_format($batch_total_modules); ?></span>
                                </div>
                            </div>

                            <div class="info-section">
                                <h3>Pallet Specifications</h3>
                                <div class="info-item">
                                    <label>Modules per Pallet:</label>
                                    <span><?php echo $batch['modules_per_pallet'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Pallets per Truck:</label>
                                    <span><?php echo $batch['pallets_per_truck'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Modules per Truck:</label>
                                    <span><?php echo $batch['modules_per_truck'] ?? 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="batch-actions">
                            <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="info-action-button">
                                View Pallets & Module Status
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data-message">
                    <p>No module batches have been added to this project yet.</p>
                    <?php if ($isGlobalAdmin): ?>
                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="info-action-button">
                            + Add Module Batch
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ==================== ANALYTICS TAB ==================== -->
<div id="analytics-tab" class="main-tab-content" style="display:none;">

    <!-- Sub-tabs for Analytics -->
    <div class="sub-tabs-container">
        <div class="sub-tabs">
            <button class="sub-tab-btn active" data-subtab="deliveries" onclick="switchSubTab('analytics', 'deliveries')">
                Deliveries
            </button>
            <button class="sub-tab-btn" data-subtab="financial" onclick="switchSubTab('analytics', 'financial')">
                Financial
            </button>
        </div>
    </div>

    <!-- ===== DELIVERIES SUB-TAB ===== -->
    <div id="subtab-deliveries" class="sub-tab-content active">
        <!-- Unit Filters (only shown on Deliveries) -->
        <div class="unit-filters-container">
            <div class="unit-filters">
                <button type="button" class="unit-filter-btn active" data-unit="mws">MWs</button>
                <button type="button" class="unit-filter-btn" data-unit="modules">Modules</button>
                <button type="button" class="unit-filter-btn" data-unit="pallets">Pallets</button>
                <button type="button" class="unit-filter-btn" data-unit="truckloads">Truckloads</button>
            </div>
        </div>

        <div class="customer-content-wrapper">
            <div class="tables-and-charts">
                <div class="left-side">
                    <h2>Next 5 Weeks of Deliveries</h2>
                    <div class="table-responsive">
                        <table id="table1">
                            <thead>
                                <tr>
                                    <th data-full="Module Type"><span class="th-short">Type</span></th>
                                    <th data-full="Total Order"><span class="th-short">Order</span></th>
                                    <th data-full="Delivered"><span class="th-short">Deliv.</span></th>
                                    <?php foreach($weeks as $wk): ?>
                                        <th><?php echo $wk['end']->format('n/j'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('delivery-row')">
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php foreach($anticipated_quantities_combined as $qq): ?>
                                        <td><?php echo number_format($qq,($view_mode=='mw')?2:0);?></td>
                                    <?php endforeach;?>
                                </tr>
                                <?php foreach($sub_rows as $lbl=>$sr): ?>
                                    <tr class="delivery-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($sr['wattage_label']);?></td>
                                        <td><?php echo number_format($sr['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($sr['delivered'],($view_mode=='mw')?2:0);?></td>
                                        <?php foreach($sr['anticipated_quantities'] as $val): ?>
                                            <td><?php echo number_format($val,($view_mode=='mw')?2:0);?></td>
                                        <?php endforeach;?>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Anticipated vs Actual Deliveries</h2>
                    <canvas id="lineChart"></canvas>
                </div>

                <div class="right-side">
                    <h2>Module Delivery Status</h2>
                    <div class="table-responsive">
                        <table id="table2">
                            <thead>
                                <tr>
                                    <th data-full="Module Type"><span class="th-short">Type</span></th>
                                    <th data-full="Total Order"><span class="th-short">Order</span></th>
                                    <th data-full="At Manufacturer"><span class="th-short">At Mfr.</span></th>
                                    <?php if ($on_water_combined > 0): ?>
                                    <th data-full="On Water"><span class="th-short">Water</span></th>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <th data-full="Cleared Customs"><span class="th-short">Customs</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                    <th data-full="In Transit to Warehouse"><span class="th-short">To Whse</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_warehouse_combined > 0): ?>
                                    <th data-full="In Warehouse"><span class="th-short">Whse</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_project_combined > 0): ?>
                                    <th data-full="In Transit to Project"><span class="th-short">To Proj</span></th>
                                    <?php endif; ?>
                                    <th data-full="Delivered to Project"><span class="th-short">Delivered</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('status-row')">
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($at_manufacturer_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php if ($on_water_combined > 0): ?>
                                    <td><?php echo number_format($on_water_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <td><?php echo number_format($cleared_customs_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                    <td><?php echo number_format($in_transit_to_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_warehouse_combined > 0): ?>
                                    <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_project_combined > 0): ?>
                                    <td><?php echo number_format($in_transit_to_project_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                </tr>
                                <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                    <tr class="status-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                        <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format(($srs['at_manufacturer'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php if ($on_water_combined > 0): ?>
                                        <td><?php echo number_format(($srs['on_water'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($cleared_customs_combined > 0): ?>
                                        <td><?php echo number_format(($srs['cleared_customs'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_transit_to_warehouse'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_warehouse_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_warehouse'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_transit_to_project_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_transit_to_project'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Delivery Overview</h2>
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== FINANCIAL SUB-TAB ===== -->
    <div id="subtab-financial" class="sub-tab-content" style="display:none;">
        <div class="customer-content-wrapper">
            <div class="tables-and-charts">
                <div class="left-side">
                    <h2>Invoices and Cashflow Forecast</h2>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Open Invoices</th>
                                    <th>Total Costs</th>
                                    <?php foreach($weeks_financial as $wf): ?>
                                        <th><?php echo $wf['end']->format('n/j'); ?></th>
                                    <?php endforeach;?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td>
                                        <a href="invoices.php?project_id=<?php echo $project_id; ?>">
                                            $<?php echo number_format($open_invoices_total,2);?>
                                        </a>
                                    </td>
                                    <td>$<?php echo number_format($total_logistics_cost,2);?></td>
                                    <?php foreach($weeks_financial as $ix=>$wf){
                                        $val = $anticipated_deliveries_financial[$ix] ?? 0;
                                        echo "<td>$".number_format($val,2)."</td>";
                                    } ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <h2>Forecasted vs Actual Cost</h2>
                    <canvas id="budgetLineChart"></canvas>
                </div>

                <div class="right-side">
                    <h2>Cost per Unit</h2>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Module Type</th>
                                    <th>Total Costs</th>
                                    <th>Price Per Pallet</th>
                                    <th>Price Per Module</th>
                                    <th>Price Per Watt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('cost-row')">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($combined_label);?></td>
                                    <td>$<?php echo number_format($combined_total_costs,2);?></td>
                                    <td>$<?php echo number_format($combined_ppp,2);?></td>
                                    <td>$<?php echo number_format($combined_ppm,2);?></td>
                                    <td>$<?php echo number_format($combined_ppw,4);?></td>
                                </tr>
                                <?php foreach($cost_data as $key=>$cd): ?>
                                    <tr class="cost-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                        <td><?php echo htmlspecialchars($cd['module_type']);?></td>
                                        <td>$<?php echo number_format($cd['total_costs'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_pallet'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_module'],2);?></td>
                                        <td>$<?php echo number_format($cd['price_per_watt'],4);?></td>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Cost Breakdown</h2>
                    <div class="chart-container">
                        <canvas id="costPieChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Unified Shipping Modal -->
<div id="shippingModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="shippingModalTitle"></h3>
            <span class="close-modal" onclick="closeShippingModal()">&times;</span>
        </div>
        <div class="modal-body" id="shippingModalContent"></div>
    </div>
</div>

<!-- Customer Shipping Modal (alias for unified) -->
<div id="customerShippingModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="customerShippingModalTitle"></h3>
            <span class="close-modal" onclick="closeCustomerShippingModal()">&times;</span>
        </div>
        <div class="modal-body" id="customerShippingModalContent"></div>
    </div>
</div>

<!-- Conversion Modal -->
<div id="conversionModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h3 id="conversionModalTitle">Conversion Needed</h3>
            <span class="close-modal" onclick="closeConversionModal()">&times;</span>
        </div>
        <div class="modal-body" id="conversionModalBody"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:15px;">
            <button class="modal-btn btn-secondary" onclick="closeConversionModal()">Cancel</button>
            <button class="modal-btn btn-primary" onclick="saveConversionModal()">Save</button>
        </div>
    </div>
</div>
