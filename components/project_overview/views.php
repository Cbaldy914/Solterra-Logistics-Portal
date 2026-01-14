<?php
/**
 * Project Overview Views Component
 * Contains both admin and user views with role-based buttons
 *
 * Required variables from parent:
 * - $role: User role (admin, global_admin, customer_admin, user)
 * - $project_id: Current project ID
 * - $deliveriesLink: Link to deliveries page
 * - $step1_completed through $step5_completed: Timeline step statuses
 * - $current_step: Current timeline step number
 * - $progress_percentage: Overall progress percentage
 * - $total_raw_modules: Total modules count
 * - $ordered_mw, $project_size_mw: MW values
 * - $actual_palletized_count, $expected_pallets: Pallet counts
 * - $status_totals: Array of status totals by shipping status
 * - $wattages: Array of wattage values
 * - $average_pallets_per_truck, $average_modules_per_truck: Averages
 * - $delivered_raw_total, $delivered_damaged_total: Delivery counts
 * - Plus many other variables used in the views
 */
?>
            <!-- Admin View Buttons -->
            <div id="admin-buttons" class="button-group" <?php echo in_array($role, ['admin', 'global_admin', 'customer_admin']) ? 'style="display: flex;"' : 'style="display: none;"'; ?>>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleModulesDropdown()">
                        Modules <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="modulesDropdown">
                        <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Module Overview</a>
                        <a href="create_shipment.php?project_id=<?php echo $project_id; ?>">Manage Pallets</a>
                        <a href="module_movements.php?project_id=<?php echo $project_id; ?>">Module Movements</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleAdminDeliveriesDropdown()">
                        Deliveries <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="adminDeliveriesDropdown">
                        <a href="create_shipment.php?project_id=<?php echo $project_id; ?>">Create Shipments</a>
                        <a href="manage_deliveries.php?project_id=<?php echo $project_id; ?>">Manage Deliveries</a>
                        <a href="scheduling.php?project_id=<?php echo $project_id; ?>">Scheduling</a>
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>">Anticipated Schedule</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleAdminDocumentsDropdown()">
                        Documents <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="adminDocumentsDropdown">
                        <a href="project_documents?project_id=<?php echo $project_id; ?>">📁 Project Documents</a>
                        <a href="global_documents.php?project_id=<?php echo $project_id; ?>&from=project_overview">🌐 Global Documents</a>
                    </div>
                </div>
                <button onclick="handleAdminWarehousing()">Warehousing</button>
                <button onclick="window.location.href='warranty.php?project_id=<?php echo $project_id; ?>'">Exceptions</button>
            </div>
            
            <!-- Customer View Buttons -->
            <div id="customer-buttons" class="button-group" <?php echo in_array($role, ['admin', 'global_admin', 'customer_admin']) ? 'style="display: none;"' : 'style="display: flex;"'; ?>>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleCustomerModulesDropdown()">
                        Modules <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="customerModulesDropdown">
                        <a href="module_overview.php?project_id=<?php echo $project_id; ?>">📦 Module Overview</a>
                        <a href="manage_pallets.php?project_id=<?php echo $project_id; ?>">📋 View Pallets</a>
                        <a href="module_movements.php?project_id=<?php echo $project_id; ?>">📍 Module Movements</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleCustomerDeliveriesDropdown()">
                        Deliveries <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="customerDeliveriesDropdown">
                        <a href="<?php echo $deliveriesLink; ?>">📋 Delivery Schedule</a>
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>">📅 Anticipated Schedule</a>
                    </div>
                </div>
                <button onclick="window.location.href='warehouse_info?project_id=<?php echo $project_id; ?>'">Warehousing</button>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleCustomerReportsDropdown()">
                        Reports <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="customerReportsDropdown">
                        <a href="project_cost_details?project_id=<?php echo $project_id; ?>">💰 Cost Report</a>
                        <a href="project_sustainability_details?project_id=<?php echo $project_id; ?>">🌱 Sustainability Report</a>
                        <a href="warranty.php?project_id=<?php echo $project_id; ?>">⚠️ Exceptions</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="dropdown-btn" onclick="toggleCustomerDocumentsDropdown()">
                        Documents <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="dropdown-content" id="customerDocumentsDropdown">
                        <a href="project_documents?project_id=<?php echo $project_id; ?>">📁 Project Documents</a>
                        <a href="global_documents.php?project_id=<?php echo $project_id; ?>&from=project_overview">🌐 Global Documents</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (in_array($role, ['admin', 'global_admin', 'customer_admin'])): ?>
        <!-- Admin Timeline View -->
        <div id="admin-view-content">
            <div class="toggle-buttons">
                <button id="progress-info-btn" class="active" onclick="showView('progress-info')">Project Progress</button>
                <button id="site-info-btn" onclick="showView('site-info')">Site Information</button>
                <button id="module-info-btn" onclick="showView('module-info')">Module Information</button>
            </div>

            <div class="admin-content-wrapper">
                <!-- Project Progress -->
                <div id="progress-info">
                    <!-- Unit Filters - Top Left -->
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
                        <li class="timeline-item<?php echo $step1_completed ? ' completed' : ''; ?><?php echo $current_step == 1 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='edit_project.php?project_id=<?php echo $project_id; ?>'">1</div>
                            <span class="label">
                                <a href="edit_project.php?project_id=<?php echo $project_id; ?>">Project Created</a>
                            </span>
                            <div class="description">Foundation established</div>
                        </li>
                        
                        <li class="timeline-item<?php echo $step2_completed ? ' completed' : ''; ?><?php echo $current_step == 2 ? ' current' : ''; ?>">
                            <?php if (in_array($_SESSION['role'], ['admin','global_admin'])): ?>
                                <div class="circle clickable" onclick="window.location.href='add_module_batch.php?project_id=<?php echo $project_id; ?>'">2</div>
                                <span class="label">
                                    <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">Add Modules</a>
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

                        <li class="timeline-item<?php echo $step3_completed ? ' completed' : ''; ?><?php echo $current_step == 3 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='module_overview.php?project_id=<?php echo $project_id; ?>'">3</div>
                            <span class="label">
                                <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Palletize Modules</a>
                            </span>
                            <div class="description">
                                <?php
                                echo number_format($actual_palletized_count) . ' of ' . number_format($expected_pallets) . ' palletized';
                                ?>
                            </div>
                        </li>
                        
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
                                ?>
                                <div class="shipping-box <?php echo $status_info["class"]; ?>"
                                     onclick="showShippingBreakdown('<?php echo $status_key; ?>')"
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
                                ?>
                                <div class="shipping-box exceptions"
                                     onclick="showShippingBreakdown('Exceptions')"
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
                        
                        <li class="timeline-item timeline-progress-step<?php echo $step5_completed ? ' completed' : ''; ?><?php echo $current_step == 5 ? ' current' : ''; ?>">
                            <!-- SVG Gradient Definition (hidden) -->
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

                            <!-- Redesigned Completion Hub -->
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
                                    <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Completed</a>
                                <?php else: ?>
                                    <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Completion</a>
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


                <!-- Site Information -->
                <div id="site-info" style="display:none;">
                    <div class="info-container">
                        <div class="header-with-button">
                            <h2>Site Information</h2>
                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
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
                                    <span><?php 
                                        $address_parts = array_filter([
                                            $project['street_address'], 
                                            $project['city'], 
                                            $project['state'], 
                                            $project['zip_code']
                                        ]);
                                        echo htmlspecialchars(implode(', ', $address_parts));
                                    ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Estimated Completion:</label>
                                    <span><?php echo $project['estimated_completion_date'] ? date('F j, Y', strtotime($project['estimated_completion_date'])) : 'Not set'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Project Size:</label>
                                    <span><?php echo number_format($project_size_mw, 2); ?> MW</span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <h3>Contact Information</h3>
                                <div class="info-item">
                                    <label>Primary Phone:</label>
                                    <span><?php echo !empty($project['phone1']) ? htmlspecialchars($project['phone1']) : 'Not provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Secondary Phone:</label>
                                    <span><?php echo !empty($project['phone2']) ? htmlspecialchars($project['phone2']) : 'Not provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Timezone:</label>
                                    <span><?php 
                                        $timezone_display = [
                                            'America/New_York' => 'Eastern',
                                            'America/Chicago' => 'Central', 
                                            'America/Denver' => 'Mountain',
                                            'America/Los_Angeles' => 'Pacific',
                                            'UTC' => 'UTC'
                                        ];
                                        echo $timezone_display[$project['timezone']] ?? htmlspecialchars($project['timezone'] ?? 'Not set');
                                    ?></span>
                                </div>
                            </div>
                            
                            <div class="info-section">
                                <h3>Additional Information</h3>
                                <div class="info-item">
                                    <label>Reference Numbers:</label>
                                    <span><?php echo !empty($project['reference_numbers']) ? htmlspecialchars($project['reference_numbers']) : 'None provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Special Instructions:</label>
                                    <span><?php echo !empty($project['instructions']) ? htmlspecialchars($project['instructions']) : 'None provided'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Additional Notes:</label>
                                    <span><?php echo !empty($project['additional_notes']) ? htmlspecialchars($project['additional_notes']) : 'None provided'; ?></span>
                                </div>
                                <?php if (!empty($project['driver_handout_url'])): ?>
                                <div class="info-item">
                                    <label>Driver Handout:</label>
                                    <span><a href="<?php echo htmlspecialchars($project['driver_handout_url']); ?>" target="_blank" style="color: #488C9A;">Download</a></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Module Information -->
                <div id="module-info" style="display:none;">
                    <div class="info-container">
                        <div class="header-with-button">
                            <h2>Module Information</h2>
                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                <div class="module-actions-dropdown">
                                    <button class="info-action-button" onclick="toggleModuleActions()" style="margin: 0;">
                                        Add/Edit Module Info ▼
                                    </button>
                                    <div class="module-actions-content" id="moduleActionsDropdown">
                                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">+ Add New Module Batch</a>
                                        <?php if (!empty($module_batches)): ?>
                                            <div style="border-top:1px solid #e5e7eb; margin:6px 0;"></div>
                                            <?php foreach ($module_batches as $i => $b): ?>
                                                <?php $batchLabel = htmlspecialchars($b['vendor_name']) . (!empty($b['is_replacement_batch']) ? ' (replacement)' : ''); ?>
                                                <a href="edit_module_batch.php?batch_id=<?php echo (int)$b['id']; ?>&project_id=<?php echo (int)$project_id; ?>">Edit Batch <?php echo $i+1; ?>: <?php echo $batchLabel; ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($module_batches)): ?>
                            <?php foreach ($module_batches as $index => $batch): ?>
                                <div class="module-batch-section" style="<?php echo $index > 0 ? 'margin-top: 30px; border-top: 2px solid #e9ecef; padding-top: 20px;' : ''; ?>">
                                    <div class="batch-header">
                                        <?php $batchLabel = htmlspecialchars($batch['vendor_name']) . (!empty($batch['is_replacement_batch']) ? ' (replacement)' : ''); ?>
                                        <h3>Module Batch <?php echo $index + 1; ?>: <?php echo $batchLabel; ?></h3>
                                        <div class="batch-meta">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span style="color: #666; font-size: 0.9em;">
                                                    Added: <?php echo date('F j, Y', strtotime($batch['created_at'])); ?>
                                                </span>

                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-grid">
                                        <div class="info-section">
                                            <h4>Basic Information</h4>
                                            <div class="info-item">
                                                <label>Vendor/Manufacturer:</label>
                                                <span><?php echo htmlspecialchars($batch['vendor_name']); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Location:</label>
                                                <span><?php echo htmlspecialchars($batch['initial_location']); ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Price per Watt:</label>
                                                <span>
                                                    <?php if (!empty($batch['cost_per_watt']) && (float)$batch['cost_per_watt'] > 0): ?>
                                                        $<?php echo number_format((float)$batch['cost_per_watt'], 4); ?> / W
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                                <div class="info-item">
                                                    <label>Module Configuration:</label>
                                                    <span>
                                                        <?php if (!empty($batch['wattages'])): ?>
                                                            <?php 
                                                            $wattage_details = [];
                                                            foreach ($batch['wattages'] as $watt_info) {
                                                                $wattage_details[] = $watt_info['wattage'] . 'W (' . number_format($watt_info['quantity']) . ' modules)';
                                                            }
                                                            echo implode('<br>', $wattage_details);
                                                            ?>
                                                        <?php else: ?>
                                                            No modules configured yet
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($batch['module_notes'])): ?>
                                                <div class="info-item">
                                                    <label>Module Notes:</label>
                                                    <span><?php echo htmlspecialchars($batch['module_notes']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                        </div>
                                        
                                        <div class="info-section">
                                            <h4>Pallet Specifications</h4>
                                            <div class="info-item">
                                                <label>Modules per Pallet:</label>
                                                <span><?php echo $batch['modules_per_pallet'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallets per Truck:</label>
                                                <span><?php echo $batch['pallets_per_truck'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Modules per Truck:</label>
                                                <span><?php echo $batch['modules_per_truck'] ?: 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Dimensions:</label>
                                                <span>
                                                    <?php if ($batch['pallet_length_mm'] && $batch['pallet_depth_mm']): ?>
                                                        <?php echo $batch['pallet_length_mm']; ?>mm × <?php echo $batch['pallet_depth_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Height (Stacked):</label>
                                                <span><?php echo $batch['pallet_double_stacked_height_mm'] ? $batch['pallet_double_stacked_height_mm'] . 'mm' : 'Not specified'; ?></span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Weight:</label>
                                                <span><?php echo $batch['pallet_total_weight_kg'] ? $batch['pallet_total_weight_kg'] . 'kg' : 'Not specified'; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="info-section">
                                            <h4>Handling Requirements</h4>
                                            <div class="info-item">
                                                <label>Forklift Requirements:</label>
                                                <span>
                                                    <?php if ($batch['forklift_truck_long_side_mm'] && $batch['forklift_truck_short_side_mm']): ?>
                                                        <?php echo $batch['forklift_truck_long_side_mm']; ?>mm × <?php echo $batch['forklift_truck_short_side_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="info-item">
                                                <label>Pallet Jack Requirements:</label>
                                                <span>
                                                    <?php if ($batch['pallet_jack_long_side_mm'] && $batch['pallet_jack_short_side_mm']): ?>
                                                        <?php echo $batch['pallet_jack_long_side_mm']; ?>mm × <?php echo $batch['pallet_jack_short_side_mm']; ?>mm
                                                    <?php else: ?>
                                                        Not specified
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($batch['stacking_in_warehouse'])): ?>
                                            <div class="info-item">
                                                <label>Warehouse Stacking:</label>
                                                <span><?php echo htmlspecialchars($batch['stacking_in_warehouse']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($batch['stacking_during_transport'])): ?>
                                            <div class="info-item">
                                                <label>Transport Stacking:</label>
                                                <span><?php echo htmlspecialchars($batch['stacking_during_transport']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($batch['module_docs_url'])): ?>
                                            <div class="info-item">
                                                <label>Module Documentation:</label>
                                                <span><a href="<?php echo htmlspecialchars($batch['module_docs_url']); ?>" target="_blank" style="color: #488C9A;">Download</a></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php /* Inline edit removed; editing should be via edit_module_batch.php */ ?>

                                    <div style="margin-top: 20px; text-align: center;">
                                        <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="info-action-button">
                                            View Pallets & Module Status
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-modules-message">
                                <p style="text-align: center; color: #666; margin: 40px 0;">
                                    No module batches have been added to this project yet.
                                </p>
                                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'global_admin'])): ?>
                                    <div style="text-align: center;">
                                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="info-action-button">
                                            + Add Module Batch
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>


    <?php else: ?>
        <!-- Regular User View -->
        <div class="toggle-buttons">
            <button id="project-progress-btn" class="active" onclick="showView('project-progress')">Project Progress</button>
            <button id="delivery-info-btn" onclick="showView('delivery-info')">Delivery Metrics</button>
            <button id="financial-info-btn" onclick="showView('financial-info')">Financial View</button>
        </div>

        <!-- Project Progress Section -->
        <div id="project-progress">
            <!-- Unit Filters - Top Left -->
            <div class="unit-filters-container">
                <div class="unit-filters">
                    <button type="button" class="unit-filter-btn active" data-unit="mws">MWs</button>
                    <button type="button" class="unit-filter-btn" data-unit="modules">Modules</button>
                    <button type="button" class="unit-filter-btn" data-unit="pallets">Pallets</button>
                    <button type="button" class="unit-filter-btn" data-unit="truckloads">Truckloads</button>
                </div>
            </div>

            <!-- Project Timeline -->
            <div class="customer-content-wrapper">
                <div class="timeline-header">
                    <h2>Project Timeline</h2>
                </div>
                
                <div class="timeline-container">
                    
                    
                    <ul class="timeline" style="--progress-width: <?php echo $progress_percentage; ?>%">
                        <li class="timeline-item<?php echo $step1_completed ? ' completed' : ''; ?><?php echo $current_step == 1 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='project_information.php?project_id=<?php echo $project_id; ?>'">1</div>
                            <div class="timeline-content">
                                <h3>Project Created</h3>
                                <p>Your solar project has been established</p>
                            </div>
                        </li>
                        <li class="timeline-item<?php echo $step2_completed ? ' completed' : ''; ?><?php echo $current_step == 2 ? ' current' : ''; ?>">
                            <div class="circle<?php echo $step2_completed ? ' clickable' : ''; ?>" <?php echo $step2_completed ? "onclick=\"window.location.href='module_overview.php?project_id={$project_id}';\"" : ''; ?>>2</div>
                            <div class="timeline-content">
                                <h3>Modules Added</h3>
                                <p>Solar modules have been added to your project</p>
                            </div>
                        </li>
                        <li class="timeline-item<?php echo $step3_completed ? ' completed' : ''; ?><?php echo $current_step == 3 ? ' current' : ''; ?>">
                            <div class="circle<?php echo $step3_completed ? ' clickable' : ''; ?>" <?php echo $step3_completed ? "onclick=\"window.location.href='manage_pallets.php?project_id={$project_id}';\"" : ''; ?>>3</div>
                            <div class="timeline-content">
                                <h3>Modules Palletized</h3>
                                <p>Modules organized and prepared for shipping</p>
                            </div>
                        </li>
                        <li class="timeline-item<?php echo $step4_completed ? ' completed' : ''; ?><?php echo $current_step == 4 ? ' current' : ''; ?>">
                            <div class="circle clickable" onclick="window.location.href='view_project.php?project_id=<?php echo $project_id; ?>'">4</div>
                            <div class="timeline-content">
                                <h3>Shipping</h3>
                                <p>Logistics in progress</p>

                                <?php if ($current_step >= 4): ?>
                                <div class="shipping-connector"></div>
                                <div class="shipping-boxes-container">
                                    <?php
                                    $user_shipping_statuses = [
                                        ["key" => "At Manufacturer", "class" => "at-manufacturer", "label" => "At Manufacturer"],
                                        ["key" => "On Water", "class" => "on-water", "label" => "On Water"],
                                        ["key" => "Cleared Customs", "class" => "cleared-customs", "label" => "Cleared Customs"],
                                        ["key" => "In Transit to Warehouse", "class" => "in-transit-warehouse", "label" => "In Transit to Warehouse"],
                                        ["key" => "In Warehouse", "class" => "in-warehouse", "label" => "In Warehouse"],
                                        ["key" => "In Transit to Project", "class" => "in-transit-project", "label" => "In Transit to Project"],
                                    ];

                                    foreach ($user_shipping_statuses as $status_info):
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
                                    ?>
                                    <div class="shipping-box-customer <?php echo $status_info["class"]; ?>"
                                         onclick="showCustomerShippingModal('<?php echo htmlspecialchars($status_key, ENT_QUOTES); ?>')"
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
                                    ?>

                                    <?php
                                    // Delivered to Project
                                    if(($delivered_raw_total > 0)):
                                        $pallets = ceil($delivered_raw_total / 30);
                                        $modules = $delivered_raw_total;
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
                                    ?>
                                    <div class="shipping-box-customer delivered"
                                         onclick="showCustomerShippingModal('Delivered')"
                                         data-pallets="<?php echo $pallets; ?>"
                                         data-modules="<?php echo $modules; ?>"
                                         data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                                         data-mws="<?php echo $mws; ?>">
                                        <div class="status-label">Delivered</div>
                                        <div class="status-count"><?php echo $mws; ?></div>
                                        <div class="status-unit">MWs</div>
                                    </div>
                                    <?php endif; ?>

                                    <?php
                                    // Exceptions
                                    $exceptions_pallets = ($status_totals["Damaged"]["pallets"] ?? 0);
                                    $exceptions_modules = ($status_totals["Damaged"]["modules"] ?? 0);
                                    if ($exceptions_pallets > 0):
                                        $pallets = $exceptions_pallets;
                                        $modules = $exceptions_modules;
                                        $truckloads = null;
                                        if (!empty($average_pallets_per_truck)) {
                                            $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                        }
                                        $mws = 0;
                                        if (!empty($wattages) && $modules > 0) {
                                            $total_watts = array_sum($wattages);
                                            $avg_wattage = count($wattages) > 0 ? ($total_watts / count($wattages)) : 0;
                                            $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                        }
                                    ?>
                                    <div class="shipping-box-customer exceptions"
                                         onclick="showCustomerShippingModal('Exceptions')"
                                         data-pallets="<?php echo $pallets; ?>"
                                         data-modules="<?php echo $modules; ?>"
                                         data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                                         data-mws="<?php echo $mws; ?>">
                                        <div class="status-label">Exceptions</div>
                                        <div class="status-count"><?php echo $mws; ?></div>
                                        <div class="status-unit">MWs</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </li>
                        <li class="timeline-item timeline-progress-step<?php echo $step5_completed ? ' completed' : ''; ?><?php echo $current_step == 5 ? ' current' : ''; ?>">
                            <!-- SVG Gradient Definition (hidden) -->
                            <svg style="position: absolute; width: 0; height: 0;">
                                <defs>
                                    <linearGradient id="completionGradientUser" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                                    </linearGradient>
                                    <linearGradient id="timelineProgressGradientUser" x1="0%" y1="0%" x2="100%" y2="0%">
                                        <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                        <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                                    </linearGradient>
                                </defs>
                            </svg>

                            <!-- Redesigned Completion Hub -->
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

                            <div class="timeline-content">
                                <?php if ($step5_completed): ?>
                                    <h3>Project Completed</h3>
                                    <p>All modules delivered and project finalized</p>
                                <?php else: ?>
                                    <h3>Project Completion</h3>
                                    <p style="font-size: 12px; line-height: 1.5; margin: 0;">
                                        <?php
                                        echo number_format($delivered_mw, 2) . ' / ' . number_format($project_size_mw, 2) . ' MW delivered';
                                        if ($ordered_mw > 0 && $ordered_mw < $project_size_mw) {
                                            echo '<br><span style="color: #856404;">(' . number_format($ordered_mw, 2) . ' MW ordered)</span>';
                                        }
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </li>
                    </ul>
                    

                </div>
            </div>
        </div>



                <!-- Delivery Metrics -->
        <div id="delivery-info" style="display: none;">
            <!-- Unit Filters - Top Left -->
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

        <!-- Financial Info -->
        <div id="financial-info" style="display: none;">
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
                                <!-- Combined row -->
                                <tr onclick="toggleSubRows('cost-row')">
                                    <td><?php echo htmlspecialchars($project['project_name']);?></td>
                                    <td><?php echo htmlspecialchars($combined_label);?></td>
                                    <td>$<?php echo number_format($combined_total_costs,2);?></td>
                                    <td>$<?php echo number_format($combined_ppp,2);?></td>
                                    <td>$<?php echo number_format($combined_ppm,2);?></td>
                                    <td>$<?php echo number_format($combined_ppw,4);?></td>
                                </tr>
                                <!-- Detailed rows -->
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

        <!-- Customer Shipping Modal -->
        <div id="customerShippingModal" class="warehouse-selection-modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="customerShippingModalTitle"></h3>
                    <span class="close-modal" onclick="closeCustomerShippingModal()">&times;</span>
                </div>
                <div class="modal-body" id="customerShippingModalContent"></div>
            </div>
        </div>
        
        <!-- Admin Conversion Prompt Modal (for Pallets/Truckloads conversions) -->
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
    <?php endif; ?>
