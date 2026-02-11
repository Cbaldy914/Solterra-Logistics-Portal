<?php
/**
 * Projection Module Selector Component
 * Shows available module batches and allows allocation to projection
 *
 * Required variables:
 * - $available_batches: Array of module batches available for this project
 * - $allocated_modules: Array of modules already allocated to current projection
 * - $can_edit: Boolean for edit permissions
 */

$manufacturer_names = $manufacturer_names ?? [];
$manufacturer_locations = $manufacturer_locations ?? [];
$manufacturer_location_map = $manufacturer_location_map ?? [];

?>

<div class="module-selector-card">
    <!-- Project Size vs Allocated Display -->
    <div class="project-allocation-tracker" id="projectAllocationTracker">
        <div class="allocation-tracker-header">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <span>Project Allocation</span>
        </div>
        <div class="allocation-tracker-content">
            <div class="allocation-bar-container">
                <div class="allocation-bar-fill" id="allocationBarFill" style="width: 0%"></div>
            </div>
            <div class="allocation-stats">
                <div class="allocation-stat">
                    <span class="stat-value" id="allocatedMWDisplay">0</span>
                    <span class="stat-label">MW Allocated</span>
                </div>
                <div class="allocation-divider">/</div>
                <div class="allocation-stat">
                    <span class="stat-value" id="projectSizeMWDisplay"><?php echo number_format($project_summary['mw'] ?? 0, 2); ?></span>
                    <span class="stat-label">MW Committed</span>
                </div>
                <div class="allocation-status" id="allocationStatus">
                    <span class="status-badge status-incomplete">Incomplete</span>
                </div>
            </div>
        </div>
    </div>

    <div class="module-list" id="moduleAllocationsList">
        <?php if (!empty($allocated_modules)): ?>
            <?php foreach ($allocated_modules as $alloc):
                // Get milestones for this allocation
                $milestones = $alloc['milestones'] ?? [];
                $po_execution_date = $alloc['po_execution_date'] ?? '';
                $is_collapsed = !empty($po_execution_date); // Collapse if PO date is set
                $requires_po_execution = false;
                foreach ($milestones as $milestone) {
                    if (($milestone['trigger_event'] ?? '') === 'po_execution') {
                        $requires_po_execution = true;
                        break;
                    }
                }
                $po_required_attr = $requires_po_execution ? 'required data-po-required="true"' : '';
            ?>
                <div class="module-item <?php echo $is_collapsed ? 'collapsed' : ''; ?>" data-allocation-id="<?php echo $alloc['id']; ?>">
                    <!-- Collapsed Summary Header -->
                    <div class="module-item-header" onclick="toggleModuleItem(<?php echo $alloc['id']; ?>)">
                        <div class="module-header-left">
                            <div class="module-vendor-name">
                                <?php echo htmlspecialchars($alloc['vendor_name'] ?? 'Unknown Vendor'); ?>
                            </div>
                            <?php if (!empty($alloc['manufacturer_address'])): ?>
                                <div class="module-manufacturer-location">
                                    <?php echo htmlspecialchars($alloc['manufacturer_address']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($po_execution_date): ?>
                                <span class="po-badge-sm">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                    PO: <?php echo date('M j, Y', strtotime($po_execution_date)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="module-header-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($alloc['wattage']); ?>W</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($alloc['quantity']); ?></span>
                                <span class="stat-label">modules</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($alloc['pallets'] ?? 0); ?></span>
                                <span class="stat-label">pallets</span>
                            </div>
                            <?php if (!empty($alloc['pallets_per_truck'])): ?>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo number_format($alloc['pallets_per_truck']); ?></span>
                                <span class="stat-label">pallets/truck</span>
                            </div>
                            <?php endif; ?>
                            <div class="stat-item highlight">
                                <span class="stat-value">$<?php echo number_format($alloc['contract_value'] ?? 0, 2); ?></span>
                            </div>
                        </div>
                        <div class="module-header-toggle">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                    </div>

                    <!-- Expanded Content -->
                    <div class="module-item-body">
                        <!-- Module Details Grid -->
                        <div class="module-details-grid">
                            <div class="detail-card">
                                <span class="detail-label">Wattage</span>
                                <span class="detail-value"><?php echo number_format($alloc['wattage']); ?>W</span>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Total Modules</span>
                                <span class="detail-value"><?php echo number_format($alloc['quantity']); ?></span>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Total Pallets</span>
                                <span class="detail-value"><?php echo number_format($alloc['pallets'] ?? 0); ?></span>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Mods/Pallet</span>
                                <span class="detail-value"><?php echo !empty($alloc['modules_per_pallet']) ? number_format($alloc['modules_per_pallet']) : '-'; ?></span>
                            </div>
                            <div class="detail-card">
                                <span class="detail-label">Pallets/Truck</span>
                                <span class="detail-value"><?php echo !empty($alloc['pallets_per_truck']) ? number_format($alloc['pallets_per_truck']) : '-'; ?></span>
                            </div>
                            <div class="detail-card highlight">
                                <span class="detail-label">Contract Value</span>
                                <span class="detail-value">$<?php echo number_format($alloc['contract_value'] ?? 0, 2); ?></span>
                            </div>
                        </div>

                        <!-- Two Column Layout for PO Date and Milestones -->
                        <div class="module-expanded-columns">
                            <?php if (!empty($milestones)): ?>
                            <!-- PO Execution Date (only shown when milestones are configured) -->
                            <?php
                                $po_is_filled = !empty($po_execution_date);
                                $po_section_class = 'po-execution-section';
                                if ($requires_po_execution) {
                                    $po_section_class .= $po_is_filled ? ' po-filled' : ' po-required';
                                } else {
                                    $po_section_class .= $po_is_filled ? ' po-filled' : ' po-optional';
                                }
                            ?>
                            <div class="<?php echo $po_section_class; ?>">
                                <div class="section-header">
                                    <?php if ($requires_po_execution && !$po_is_filled): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <span style="color: #dc3545;">PO Execution Date <span style="color: #dc3545;">*</span></span>
                                    <?php elseif ($po_is_filled): ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <span style="color: #28a745;">PO Execution Date</span>
                                    <?php else: ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6c757d" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <span style="color: #6c757d;">PO Execution Date</span>
                                    <?php endif; ?>
                                </div>
                                <div class="po-execution-input">
                                    <input type="text" class="form-input flatpickr-date po-date-input"
                                           data-allocation-id="<?php echo $alloc['id']; ?>"
                                           data-po-required="<?php echo $requires_po_execution ? 'true' : 'false'; ?>"
                                           value="<?php echo htmlspecialchars($po_execution_date); ?>"
                                           placeholder="<?php echo $requires_po_execution ? 'Required — select PO execution date' : 'Optional — select date'; ?>"
                                           <?php echo $can_edit ? '' : 'disabled'; ?>
                                           <?php echo $requires_po_execution ? 'required' : ''; ?>>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Milestones Section -->
                            <div class="milestones-section" <?php echo empty($milestones) ? 'style="grid-column: 1 / -1;"' : ''; ?>>
                                <div class="section-header">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    <span>Milestone Payments</span>
                                </div>
                                <?php if (!empty($milestones)): ?>
                                    <div class="milestones-list">
                                        <?php foreach ($milestones as $milestone):
                                            // Map trigger codes to readable labels
                                            $triggerLabels = [
                                                'po_execution' => 'PO Execution',
                                                'shipping' => 'Shipping',
                                                'customs_cleared' => 'Customs Clearance',
                                                'project_delivery' => 'Project Delivery'
                                            ];
                                            $triggerEvent = $milestone['trigger_event'] ?? '';
                                            $displayLabel = $triggerLabels[$triggerEvent] ?? $milestone['milestone_name'] ?? $milestone['name'] ?? 'Milestone';
                                        ?>
                                            <div class="milestone-row">
                                                <div class="milestone-info">
                                                    <span class="milestone-name"><?php echo htmlspecialchars($displayLabel); ?></span>
                                                </div>
                                                <div class="milestone-amount">
                                                    <?php
                                                        $amount = $milestone['amount'] ?? 0;
                                                        $percentage = $milestone['percentage'] ?? 0;
                                                        if ($percentage > 0) {
                                                            echo number_format($percentage, 1) . '%';
                                                        } else {
                                                            echo '$' . number_format($amount, 2);
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="milestones-default-note">
                                        <div class="milestone-row">
                                            <div class="milestone-info">
                                                <span class="milestone-name">Project Delivery</span>
                                                <span class="milestone-default-tag">Default</span>
                                            </div>
                                            <div class="milestone-amount">100.0%</div>
                                        </div>
                                        <div class="milestones-default-info">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                            <span>No milestones configured — defaults to 100% upon project delivery.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="module-item-actions">
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-sm btn-edit"
                                        onclick="openEditModuleAllocationModal(<?php echo $alloc['id']; ?>)">
                                    Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="removeModuleAllocation(<?php echo $alloc['id']; ?>)">
                                    Remove Batch
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                    <line x1="8" y1="21" x2="16" y2="21"/>
                    <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                <p>No modules allocated to this projection yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($can_edit): ?>
    <!-- Add Modules Button + Save & Continue -->
    <div class="modules-section-actions" id="modulesSectionActions">
        <button type="button" class="btn btn-secondary btn-add-modules" onclick="openAddModulesModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>Add Modules</span>
        </button>
        <button type="button" class="btn btn-primary btn-save-continue" onclick="saveAndContinueToLogistics()">
            <span>Save & Continue to Logistics</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        </button>
    </div>
    <?php endif; ?>

<!-- Add Modules Modal -->
<div id="addModulesModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeAddModulesModal()"></div>
    <div class="modal-content modal-lg add-modules-modal">
        <div class="modal-header">
            <h3>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                    <line x1="8" y1="21" x2="16" y2="21"/>
                    <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                Add Modules to Projection
            </h3>
            <button type="button" class="modal-close" onclick="closeAddModulesModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="module-mode-tabs">
                <button type="button" class="mode-tab <?php echo !empty($available_batches) ? 'active' : ''; ?>" data-mode="existing" onclick="switchModuleMode('existing')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    Select Existing Batch
                </button>
                <button type="button" class="mode-tab <?php echo empty($available_batches) ? 'active' : ''; ?>" data-mode="manual" onclick="switchModuleMode('manual')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Manual Entry
                </button>
            </div>

            <!-- Existing Batch Selection -->
            <div class="module-mode-panel <?php echo !empty($available_batches) ? 'active' : ''; ?>" id="existing-batch-panel">
                <?php if (!empty($available_batches)): ?>
                    <div class="batch-list">
                        <?php foreach ($available_batches as $batch): ?>
                            <div class="batch-item" data-batch-id="<?php echo $batch['id']; ?>">
                                <div class="batch-info">
                                    <div class="batch-vendor"><?php echo htmlspecialchars($batch['vendor_name']); ?></div>
                                    <div class="batch-manufacturer">
                                        <?php echo htmlspecialchars($batch['manufacturer_name'] ?? 'Unknown manufacturer'); ?>
                                        <?php if (!empty($batch['manufacturer_address'])): ?>
                                            - <?php echo htmlspecialchars($batch['manufacturer_address']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="batch-details">
                                        <span>Wattages: <?php echo htmlspecialchars($batch['wattages']); ?>W</span>
                                        <span>&bull;</span>
                                        <span>Total: <?php echo number_format($batch['total_quantity']); ?> modules</span>
                                        <?php if (!empty($batch['modules_per_pallet'])): ?>
                                            <span>&bull;</span>
                                            <span><?php echo $batch['modules_per_pallet']; ?> mods/pallet</span>
                                        <?php endif; ?>
                                        <?php if (!empty($batch['pallets_per_truck'])): ?>
                                            <span>&bull;</span>
                                            <span><?php echo $batch['pallets_per_truck']; ?> pallets/truck</span>
                                        <?php endif; ?>
                                        <?php if ($batch['has_milestones']): ?>
                                            <span class="milestone-badge-sm">Milestones configured</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="batch-action">
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="selectModuleBatchFromModal(<?php echo $batch['id']; ?>, '<?php echo implode(',', $batch['wattage_list']); ?>', <?php echo $batch['total_quantity']; ?>, <?php echo $batch['modules_per_pallet'] ?? 30; ?>, <?php echo $batch['pallets_per_truck'] ?? 20; ?>)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                        Add
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <p>No module batches available for this project.</p>
                        <p><small>Use Manual Entry to add module details for projection purposes.</small></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Manual Entry Form -->
            <div class="module-mode-panel <?php echo empty($available_batches) ? 'active' : ''; ?>" id="manual-entry-panel">
                <div class="manual-entry-intro">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4"/>
                        <path d="M12 8h.01"/>
                    </svg>
                    <span>Enter module details for projection purposes. This won't create an actual module batch.</span>
                </div>

                <div class="manual-form-grid">
                    <div class="form-group">
                        <label class="form-label">Manufacturer/Vendor <span class="required">*</span></label>
                        <select id="manualManufacturerId" class="form-input" onchange="handleManualManufacturerChange(this)">
                            <option value="">Select Manufacturer</option>
                            <?php foreach ($manufacturers_for_manual ?? [] as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>">
                                    <?php echo htmlspecialchars($m['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="add_new" style="background-color: #f0f8ff; font-style: italic;">+ Add New Manufacturer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Location/Origin</label>
                        <select id="manualLocationId" class="form-input" disabled>
                            <option value="">Select a manufacturer first</option>
                        </select>
                    </div>
                </div>

                <div class="wattage-entries-section">
                    <label class="form-label">Module Wattages & Quantities <span class="required">*</span></label>
                    <div id="manualWattageEntries">
                        <div class="wattage-entry-row">
                            <div class="wattage-input-group">
                                <input type="number" class="form-input wattage-input" placeholder="Wattage (W)" min="100" max="1000">
                                <input type="number" class="form-input quantity-input" placeholder="Quantity" min="1">
                                <button type="button" class="btn-remove-wattage" onclick="removeManualWattageEntry(this)" style="display: none;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/>
                                        <line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-add-wattage" onclick="addManualWattageEntry()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add Another Wattage
                    </button>
                </div>

                <div class="manual-form-grid">
                    <div class="form-group">
                        <label class="form-label">Modules per Pallet <span class="required">*</span></label>
                        <input type="number" id="manualModsPerPallet" class="form-input" placeholder="e.g., 30" min="1" value="30">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Pallets per Truck <span class="required">*</span></label>
                        <input type="number" id="manualPalletsPerTruck" class="form-input" placeholder="e.g., 20" min="1" value="20">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Cost per Watt ($)</label>
                        <input type="number" id="manualCostPerWatt" class="form-input" placeholder="e.g., 0.25" step="0.01" min="0">
                    </div>
                </div>

                <div class="manual-milestones-section">
                    <label class="form-label">Payment Milestones (optional)</label>
                    <div id="manualMilestoneEntries">
                        <div class="milestone-entry-row-simple">
                            <select class="form-input milestone-trigger-input">
                                <option value="">Select trigger event</option>
                                <option value="po_execution">PO Execution</option>
                                <option value="shipping">Shipping</option>
                                <option value="customs_cleared">Customs Clearance</option>
                                <option value="project_delivery">Project Delivery</option>
                            </select>
                            <div class="milestone-percentage-wrapper">
                                <input type="number" class="form-input milestone-percentage-input" placeholder="0" min="0" max="100" step="0.1">
                                <span class="percentage-suffix">%</span>
                            </div>
                            <button type="button" class="btn-remove-milestone" onclick="removeManualMilestoneEntry(this)" style="display: none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn-add-milestone" onclick="addManualMilestoneEntry()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add Milestone
                    </button>
                </div>

                <div class="btn-group modal-actions">
                    <button type="button" class="btn btn-primary" onclick="addManualModuleEntryFromModal()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px;">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add to Projection
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeAddModulesModal()">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Wattage/Quantity Selection Sub-Modal -->
<div id="wattageQuantityModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeWattageQuantityModal()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>Allocation Details</h3>
            <button type="button" class="modal-close" onclick="closeWattageQuantityModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="selectedBatchId" value="">

            <div class="form-group">
                <label class="form-label">Wattage</label>
                <select id="selectedWattage" class="form-input">
                    <!-- Populated dynamically -->
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Quantity</label>
                <input type="number" id="selectedQuantity" class="form-input" min="1" placeholder="Number of modules">
                <small class="form-help">Max available: <span id="maxQuantityDisplay">0</span></small>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="confirmModuleAllocation()">
                    Add to Projection
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeWattageQuantityModal()">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Module Allocation Modal -->
<div id="editModuleAllocationModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeEditModuleAllocationModal()"></div>
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>Edit Module Allocation</h3>
            <button type="button" class="modal-close" onclick="closeEditModuleAllocationModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editAllocationId" value="">
            <input type="hidden" id="editAllocationIsProjectionOnly" value="0">

            <div id="editAllocationHint" class="manual-entry-intro" style="margin-bottom: 16px; display: none;"></div>

            <div class="form-group" id="editBatchGroup">
                <label class="form-label">Manufacturer Batch</label>
                <select id="editLinkedBatchId" class="form-input" onchange="handleEditBatchChange()"></select>
            </div>

            <div class="manual-form-grid">
                <div class="form-group">
                    <label class="form-label">Vendor / Manufacturer</label>
                    <input type="text" id="editVendorName" class="form-input" placeholder="Vendor name">
                </div>
                <div class="form-group">
                    <label class="form-label">Origin Location</label>
                    <input type="text" id="editManufacturerAddress" class="form-input" placeholder="Address or location">
                </div>
            </div>

            <div class="manual-form-grid">
                <div class="form-group">
                    <label class="form-label">Wattage (W) <span class="required">*</span></label>
                    <input type="number" id="editWattage" class="form-input" min="1" oninput="updateEditModulePreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity <span class="required">*</span></label>
                    <input type="number" id="editQuantity" class="form-input" min="1" oninput="updateEditModulePreview()">
                </div>
            </div>

            <div class="manual-form-grid">
                <div class="form-group">
                    <label class="form-label">Modules / Pallet</label>
                    <input type="number" id="editModulesPerPallet" class="form-input" min="1" oninput="updateEditModulePreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Pallets / Truck</label>
                    <input type="number" id="editPalletsPerTruck" class="form-input" min="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Cost / Watt ($)</label>
                    <input type="number" id="editCostPerWatt" class="form-input" min="0" step="0.0001" oninput="updateEditModulePreview()">
                </div>
            </div>

            <div class="manual-form-grid" style="margin-bottom: 0;">
                <div class="form-group">
                    <label class="form-label">Projected Pallets</label>
                    <input type="text" id="editProjectedPallets" class="form-input" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Projected Contract Value</label>
                    <input type="text" id="editProjectedContractValue" class="form-input" readonly>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="saveModuleAllocationEdits()">
                    Save Changes
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModuleAllocationModal()">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.module-selector-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(72, 140, 154, 0.1);
    margin-bottom: 24px;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 12px;
}

.card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.1em;
    font-weight: 600;
    color: #293E4C;
}

.card-title svg {
    color: #488C9A;
}

.card-summary {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.95em;
}

.summary-value {
    font-weight: 700;
    color: #488C9A;
}

.summary-label {
    color: #6c757d;
}

.summary-divider {
    color: #dee2e6;
    margin: 0 8px;
}

.module-list {
    padding: 16px 24px;
}

.module-selector-panel {
    padding: 20px 24px 28px;
    border-top: 1px solid #e9ecef;
    background: #fafbfc;
    border-radius: 0 0 16px 16px;
}

.module-selector-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid rgba(72, 140, 154, 0.2);
    background: white;
    color: #293E4C;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.module-selector-toggle:hover {
    border-color: rgba(72, 140, 154, 0.45);
    box-shadow: 0 6px 16px rgba(72, 140, 154, 0.12);
}

.module-selector-toggle .toggle-icon {
    transition: transform 0.2s ease;
}

.module-selector-toggle.open .toggle-icon {
    transform: rotate(180deg);
}

.module-selector-content {
    display: none;
    margin-top: 16px;
}

.module-selector-content.open {
    display: block;
}

.module-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 16px;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
}

.module-item:hover {
    border-color: #488C9A;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.1);
}

.module-item:last-child {
    margin-bottom: 0;
}

.module-info {
    flex: 1;
}

.module-vendor {
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.manufacturer-tag {
    font-size: 0.8em;
    font-weight: 500;
    color: #6c757d;
    background: #f8f9fa;
    padding: 2px 8px;
    border-radius: 6px;
}

.module-details {
    font-size: 0.9em;
    color: #495057;
    margin-bottom: 8px;
}

.detail-item {
    display: inline;
}

.detail-divider {
    color: #dee2e6;
    margin: 0 8px;
}

.module-costs {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 0.9em;
}

.cost-contract {
    color: #495057;
}

.milestone-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #28a745;
    font-size: 0.85em;
}

.milestone-indicator.milestone-warning {
    color: #ffc107;
}

.module-actions {
    margin-left: 16px;
}

.btn-remove-module {
    padding: 8px;
    border: none;
    background: transparent;
    color: #dc3545;
    cursor: pointer;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.btn-remove-module:hover {
    background: #f8d7da;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}

.empty-state svg {
    margin-bottom: 16px;
}

.empty-state p {
    margin: 0;
}

.add-module-section {
    padding: 16px 24px;
    border-top: 1px solid #e9ecef;
}

.btn-add-module {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.1) 100%);
    border: 2px dashed #488C9A;
    border-radius: 10px;
    color: #488C9A;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    width: 100%;
    justify-content: center;
}

.btn-add-module:hover {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.2) 0%, rgba(58, 110, 127, 0.2) 100%);
    border-style: solid;
}

/* Modal Styles */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1000;
    display: none;
    align-items: center;
    justify-content: center;
}

.modal[style*="display: flex"] {
    display: flex;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-content.modal-sm {
    max-width: 400px;
}

.modal-content.modal-lg {
    max-width: 900px;
}

/* Module Mode Tabs */
.module-mode-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e9ecef;
}

.mode-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 0.9em;
    font-weight: 600;
    color: #6c757d;
    cursor: pointer;
    transition: all 0.2s ease;
    flex: 1;
    justify-content: center;
}

.mode-tab:hover {
    background: #f0f4f5;
    border-color: #488C9A;
    color: #488C9A;
}

.mode-tab.active {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
    border-color: #488C9A;
    color: #488C9A;
}

.module-mode-panel {
    display: none;
}

.module-mode-panel.active {
    display: block;
}

/* Manual Entry Form */
.manual-entry-intro {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 0.9em;
    color: #495057;
}

.manual-entry-intro svg {
    flex-shrink: 0;
    margin-top: 2px;
}

.manual-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.wattage-entries-section {
    margin-bottom: 20px;
}

.manual-milestones-section {
    margin-bottom: 20px;
}

.milestone-entry-row {
    display: grid;
    grid-template-columns: 2fr 2fr 1fr auto;
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
}

.btn-add-milestone {
    background: transparent;
    border: 1px dashed #adb5bd;
    color: #6c757d;
    padding: 8px 12px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.btn-add-milestone:hover {
    border-color: #488C9A;
    color: #488C9A;
}

.btn-remove-milestone {
    background: #f8d7da;
    border: none;
    color: #dc3545;
    padding: 8px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.wattage-entry-row {
    margin-bottom: 10px;
}

.wattage-input-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.wattage-input-group .form-input {
    flex: 1;
}

.wattage-input-group .wattage-input {
    max-width: 140px;
}

.wattage-input-group .quantity-input {
    max-width: 140px;
}

.btn-remove-wattage {
    padding: 8px;
    background: transparent;
    border: 1px solid #dc3545;
    border-radius: 8px;
    color: #dc3545;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-remove-wattage:hover {
    background: #dc3545;
    color: white;
}

.btn-add-wattage {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: transparent;
    border: 1px dashed #488C9A;
    border-radius: 8px;
    color: #488C9A;
    font-size: 0.85em;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-add-wattage:hover {
    background: rgba(72, 140, 154, 0.1);
    border-style: solid;
}

.required {
    color: #dc3545;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
}

.modal-header h3 {
    margin: 0;
    font-size: 1.2em;
    color: #293E4C;
}

.modal-close {
    padding: 4px;
    border: none;
    background: transparent;
    cursor: pointer;
    color: #6c757d;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: #f8f9fa;
    color: #293E4C;
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
}

.batch-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.batch-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    transition: all 0.2s ease;
}

.batch-item:hover {
    border-color: #488C9A;
    background: rgba(72, 140, 154, 0.02);
}

.batch-item.is-disabled {
    opacity: 0.6;
    background: #f8f9fa;
}

.batch-item.is-disabled .btn {
    background: #e9ecef;
    color: #6c757d;
    cursor: not-allowed;
    box-shadow: none;
}

.batch-vendor {
    font-weight: 600;
    color: #293E4C;
}

.batch-manufacturer {
    font-size: 0.9em;
    color: #6c757d;
    margin-top: 4px;
}

.batch-details {
    font-size: 0.85em;
    color: #495057;
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.milestone-badge-sm {
    background: #d4edda;
    color: #155724;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.85em;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 8px;
    font-size: 0.9em;
}

.form-input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1em;
    font-family: inherit;
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    border-color: #488C9A;
    box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
}

.form-help {
    display: block;
    margin-top: 6px;
    font-size: 0.85em;
    color: #6c757d;
}

.btn-group {
    display: flex;
    gap: 12px;
    margin-top: 24px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: inherit;
}

.btn-primary {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
}

.btn-secondary {
    background: #e9ecef;
    color: #495057;
}

.btn-secondary:hover {
    background: #dee2e6;
}

/* Collapsible Module Item Styles - Redesigned for better layout */
.module-item {
    flex-direction: column;
    padding: 0;
    overflow: hidden;
    width: 100%;
}

.module-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    cursor: pointer;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 1px solid #e9ecef;
    transition: all 0.2s ease;
    gap: 20px;
    width: 100%;
}

.module-item-header:hover {
    background: linear-gradient(135deg, #f0f4f5 0%, #f8f9fa 100%);
}

.module-item.collapsed .module-item-header {
    background: white;
    border-bottom: none;
}

.module-header-left {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 180px;
}

.module-vendor-name {
    font-weight: 700;
    font-size: 1.05em;
    color: #293E4C;
}

.module-manufacturer-location {
    font-size: 0.9em;
    color: #6c757d;
    line-height: 1.2;
}

.module-header-stats {
    display: flex;
    align-items: center;
    gap: 24px;
    flex: 1;
    justify-content: flex-end;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 16px;
    background: rgba(72, 140, 154, 0.05);
    border-radius: 10px;
    min-width: 80px;
}

.stat-item.highlight {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.1) 100%);
}

.stat-item .stat-value {
    font-weight: 700;
    font-size: 1.1em;
    color: #293E4C;
}

.stat-item.highlight .stat-value {
    color: #488C9A;
    font-size: 1.15em;
}

.stat-item .stat-label {
    font-size: 0.75em;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-top: 2px;
}

.module-header-toggle {
    padding: 8px;
    color: #6c757d;
    transition: transform 0.3s ease;
    margin-left: 12px;
}

.module-item.collapsed .module-header-toggle {
    transform: rotate(-90deg);
}

.po-badge-sm {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #d4edda;
    color: #155724;
    padding: 4px 10px;
    border-radius: 12px;
    font-weight: 500;
    font-size: 0.85em;
}

.po-badge-sm svg {
    color: #28a745;
}

.module-item-body {
    padding: 24px;
    background: white;
    transition: all 0.3s ease;
    width: 100%;
}

.module-item.collapsed .module-item-body {
    display: none;
}

/* Module Details Grid - Full Width */
.module-details-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.detail-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 12px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    text-align: center;
}

.detail-card.highlight {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
    border-color: rgba(72, 140, 154, 0.2);
}

.detail-card .detail-label {
    font-size: 0.75em;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}

.detail-card .detail-value {
    font-weight: 700;
    font-size: 1.2em;
    color: #293E4C;
}

.detail-card.highlight .detail-value {
    color: #488C9A;
}

/* Two Column Layout for Expanded Content */
.module-expanded-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 20px;
}

/* Section Header (shared between PO and Milestones) */
.section-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 12px;
    font-size: 0.95em;
}

/* PO Execution Section */
.po-execution-section {
    border-radius: 12px;
    padding: 20px;
}

.po-execution-section.po-required {
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.03) 0%, rgba(220, 53, 69, 0.06) 100%);
    border: 1px solid rgba(220, 53, 69, 0.25);
    border-left: 3px solid #dc3545;
}

.po-execution-section.po-filled {
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.03) 0%, rgba(40, 167, 69, 0.06) 100%);
    border: 1px solid rgba(40, 167, 69, 0.25);
    border-left: 3px solid #28a745;
}

.po-execution-section.po-optional {
    background: linear-gradient(135deg, rgba(108, 117, 125, 0.03) 0%, rgba(108, 117, 125, 0.06) 100%);
    border: 1px solid rgba(108, 117, 125, 0.2);
    border-left: 3px solid #adb5bd;
}

.po-execution-input .form-input {
    background: white;
    width: 100%;
}

/* Milestones Section */
.milestones-section {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 20px;
}

.milestones-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.milestone-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: white;
    border-radius: 8px;
    border-left: 3px solid #488C9A;
}

.milestone-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.milestone-name {
    font-weight: 600;
    color: #293E4C;
    font-size: 0.95em;
}

.milestone-trigger {
    font-size: 0.8em;
    color: #6c757d;
}

.milestone-amount {
    font-weight: 700;
    color: #488C9A;
    font-size: 1em;
}

.milestones-empty {
    padding: 16px;
    background: white;
    border-radius: 8px;
    text-align: center;
    color: #6c757d;
}

.milestones-empty p {
    margin: 0 0 4px 0;
    font-size: 0.95em;
}

.milestones-empty small {
    color: #adb5bd;
}

.milestones-default-note {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.milestones-default-note .milestone-row {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.06) 0%, rgba(72, 140, 154, 0.02) 100%);
    border-left-color: #6c757d;
    border-left-style: dashed;
}

.milestone-default-tag {
    display: inline-flex;
    align-items: center;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 0.75em;
    font-weight: 600;
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
    margin-left: 8px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.milestones-default-info {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82em;
    color: #6c757d;
    padding: 8px 12px;
    background: rgba(108, 117, 125, 0.06);
    border-radius: 8px;
}

.milestones-default-info svg {
    flex-shrink: 0;
    color: #6c757d;
}

/* Module Item Actions */
.module-item-actions {
    display: flex;
    justify-content: center;
    gap: 16px;
    padding-top: 16px;
    border-top: 1px solid #e9ecef;
}

/* Responsive adjustments */
@media (max-width: 1200px) {
    .module-details-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 900px) {
    .module-expanded-columns {
        grid-template-columns: 1fr;
    }

    .module-header-stats {
        flex-wrap: wrap;
        gap: 12px;
    }

    .stat-item {
        min-width: 70px;
        padding: 6px 12px;
    }

    .milestone-entry-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .module-details-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .module-item-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }

    .module-header-stats {
        width: 100%;
        justify-content: flex-start;
    }
}
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e9ecef;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 0.9em;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-edit {
    background: #e8f4f6;
    color: #1f5e69;
}

.btn-edit:hover {
    background: #d8ebef;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
}

/* Project Allocation Tracker */
.project-allocation-tracker {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border: 1px solid rgba(72, 140, 154, 0.15);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 16px;
}

.allocation-tracker-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 16px;
    font-size: 0.95em;
}

.allocation-tracker-content {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.allocation-bar-container {
    height: 12px;
    background: #e9ecef;
    border-radius: 6px;
    overflow: hidden;
    position: relative;
}

.allocation-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #488C9A 0%, #3A6E7F 100%);
    border-radius: 6px;
    transition: width 0.5s ease;
    position: relative;
}

.allocation-bar-fill.complete {
    background: linear-gradient(90deg, #28a745 0%, #20923c 100%);
}

.allocation-bar-fill.over {
    background: linear-gradient(90deg, #ffc107 0%, #e0a800 100%);
}

.allocation-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.allocation-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.allocation-stat .stat-value {
    font-size: 1.4em;
    font-weight: 700;
    color: #488C9A;
}

.allocation-stat .stat-label {
    font-size: 0.75em;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.allocation-divider {
    font-size: 1.5em;
    color: #dee2e6;
    font-weight: 300;
}

.allocation-status {
    margin-left: auto;
}

.allocation-status .status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 600;
}

.allocation-status .status-incomplete {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.allocation-status .status-complete {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.allocation-bar-fill.complete {
    background: linear-gradient(90deg, #28a745 0%, #20923c 100%);
    box-shadow: 0 0 12px rgba(40, 167, 69, 0.4);
}

.allocation-stat.complete .stat-value {
    color: #28a745;
}

.allocation-status .status-over {
    background: rgba(255, 193, 7, 0.15);
    color: #856404;
}

/* Modules Section Actions */
.modules-section-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid #e9ecef;
    background: #fafbfc;
    border-radius: 0 0 16px 16px;
}

.btn-add-modules {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    border: 2px solid rgba(72, 140, 154, 0.3);
    color: #488C9A;
}

.btn-add-modules:hover {
    background: rgba(72, 140, 154, 0.05);
    border-color: #488C9A;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.15);
}

/* Add Modules Modal */
.add-modules-modal {
    max-width: 900px;
    max-height: 85vh;
}

.add-modules-modal .modal-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
}

.add-modules-modal .modal-header h3 svg {
    color: #488C9A;
}

.add-modules-modal .modal-body {
    max-height: calc(85vh - 120px);
    overflow-y: auto;
}

.add-modules-modal .batch-list {
    max-height: 300px;
    overflow-y: auto;
}

.add-modules-modal .batch-item {
    padding: 14px 16px;
}

.add-modules-modal .batch-action .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.batch-item.just-added {
    background: rgba(40, 167, 69, 0.05);
    border-color: rgba(40, 167, 69, 0.3);
}

.batch-item.is-disabled .batch-action .btn {
    background: #e9ecef;
    color: #6c757d;
    box-shadow: none;
}

.batch-item.is-disabled .batch-action .btn svg {
    color: #28a745;
}

.modal-actions {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.btn-save-continue {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    font-size: 1em;
    font-weight: 600;
}

.btn-save-continue svg {
    transition: transform 0.2s ease;
}

.btn-save-continue:hover svg {
    transform: translateX(4px);
}

/* Milestone Entry Row Simple (without name) */
.milestone-entry-row-simple {
    display: grid;
    grid-template-columns: 2fr 1fr auto;
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
}

.milestone-percentage-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.milestone-percentage-wrapper .form-input {
    padding-right: 30px;
}

.milestone-percentage-wrapper .percentage-suffix {
    position: absolute;
    right: 12px;
    color: #6c757d;
    font-weight: 500;
    pointer-events: none;
}

@media (max-width: 600px) {
    .allocation-stats {
        justify-content: center;
    }

    .allocation-status {
        margin-left: 0;
        width: 100%;
        text-align: center;
        margin-top: 8px;
    }

    .milestone-entry-row-simple {
        grid-template-columns: 1fr;
    }

    .modules-section-actions {
        justify-content: stretch;
    }

    .btn-save-continue {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script>
function handleManualManufacturerChange(select) {
    const locationSelect = document.getElementById('manualLocationId');

    if (select.value === 'add_new') {
        window.open('add_manufacturer.php', '_blank');
        select.value = '';
        locationSelect.innerHTML = '<option value="">Select a manufacturer first</option>';
        locationSelect.disabled = true;
        return;
    }
    if (!select.value) {
        locationSelect.innerHTML = '<option value="">Select a manufacturer first</option>';
        locationSelect.disabled = true;
        return;
    }
    locationSelect.disabled = true;
    locationSelect.innerHTML = '<option>Loading locations...</option>';
    fetch('get_manufacturer_locations.php?manufacturer_id=' + encodeURIComponent(select.value))
        .then(r => r.json())
        .then(data => {
            locationSelect.innerHTML = '';
            if (data && Array.isArray(data.locations) && data.locations.length > 0) {
                const def = document.createElement('option');
                def.value = ''; def.textContent = 'Select a location';
                locationSelect.appendChild(def);
                data.locations.forEach(loc => {
                    const opt = document.createElement('option');
                    opt.value = loc.id;
                    opt.textContent = (loc.location_name ? (loc.location_name + ' — ') : '') + loc.formatted_address;
                    opt.dataset.address = loc.formatted_address || '';
                    locationSelect.appendChild(opt);
                });
            } else {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = 'No active locations';
                locationSelect.appendChild(opt);
            }
            locationSelect.disabled = false;
        })
        .catch(() => {
            locationSelect.innerHTML = '<option value="">Error loading locations</option>';
            locationSelect.disabled = false;
        });
}

function switchModuleMode(mode) {
    // Update tabs
    document.querySelectorAll('.mode-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.mode === mode);
    });

    // Update panels
    document.getElementById('existing-batch-panel').classList.toggle('active', mode === 'existing');
    document.getElementById('manual-entry-panel').classList.toggle('active', mode === 'manual');
}

// Add Modules Modal Functions
function openAddModulesModal() {
    const modal = document.getElementById('addModulesModal');
    if (modal) {
        modal.style.display = 'flex';
        updateAvailableBatchStates();
    }
}

function closeAddModulesModal() {
    const modal = document.getElementById('addModulesModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function selectModuleBatchFromModal(batchId, wattages, totalQuantity, modsPerPallet, palletsPerTruck) {
    // Call the main selectModuleBatch function
    if (typeof window.selectModuleBatch === 'function') {
        window.selectModuleBatch(batchId, wattages, totalQuantity, modsPerPallet, palletsPerTruck);
    } else if (typeof selectModuleBatch === 'function') {
        selectModuleBatch(batchId, wattages, totalQuantity, modsPerPallet, palletsPerTruck);
    }

    // Update batch states in modal
    updateAvailableBatchStates();

    // Keep modal open so user can add more batches
    // Show success feedback in the batch item
    const batchItem = document.querySelector(`.batch-item[data-batch-id="${batchId}"]`);
    if (batchItem) {
        batchItem.classList.add('is-disabled', 'just-added');
        const btn = batchItem.querySelector('button');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Added';
        }
        setTimeout(() => batchItem.classList.remove('just-added'), 1500);
    }
}

function addManualModuleEntryFromModal() {
    // Call the main addManualModuleEntry function
    addManualModuleEntry();

    // Close modal after adding
    closeAddModulesModal();
}

function toggleModuleSelectorPanel() {
    const content = document.getElementById('moduleSelectorContent');
    const button = document.querySelector('.module-selector-toggle');
    if (!content || !button) return;

    const isOpen = content.classList.toggle('open');
    button.classList.toggle('open', isOpen);
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    const label = button.querySelector('.toggle-label');
    if (label) {
        label.textContent = isOpen ? 'Hide Module Options' : 'Add Modules';
    }

    if (isOpen) {
        updateAvailableBatchStates();
    }
}

function isBatchAlreadySelected(batchId) {
    if (!window.workingState || !Array.isArray(workingState.moduleAllocations)) {
        return false;
    }
    return workingState.moduleAllocations.some(allocation => String(allocation.module_id) === String(batchId));
}

function updateAvailableBatchStates() {
    document.querySelectorAll('.batch-item').forEach(item => {
        const batchId = item.dataset.batchId;
        const button = item.querySelector('button');
        const isSelected = isBatchAlreadySelected(batchId);

        item.classList.toggle('is-disabled', isSelected);
        if (button) {
            button.disabled = isSelected;
            button.textContent = isSelected ? 'Added' : 'Select';
        }
    });
}

function selectModuleBatch(batchId, wattages, totalQuantity, modsPerPallet, palletsPerTruck) {
    if (isBatchAlreadySelected(batchId)) {
        if (typeof showToast === 'function') {
            showToast('This batch is already added to the projection.', 'error');
        } else {
            alert('This batch is already added to the projection.');
        }
        return;
    }
    // Populate wattage selector
    const wattageSelect = document.getElementById('selectedWattage');
    wattageSelect.innerHTML = '';

    const wattageList = wattages.split(',');
    wattageList.forEach(w => {
        const option = document.createElement('option');
        option.value = w;
        option.textContent = w + 'W';
        wattageSelect.appendChild(option);
    });

    // Set batch ID and max quantity
    document.getElementById('selectedBatchId').value = batchId;
    document.getElementById('maxQuantityDisplay').textContent = totalQuantity;
    document.getElementById('selectedQuantity').max = totalQuantity;
    document.getElementById('selectedQuantity').value = totalQuantity; // Default to all

    // Store modules per pallet and pallets per truck for calculations
    document.getElementById('selectedBatchId').dataset.modsPerPallet = modsPerPallet || 30;
    document.getElementById('selectedBatchId').dataset.palletsPerTruck = palletsPerTruck || 20;

    // Show wattage/quantity modal
    document.getElementById('wattageQuantityModal').style.display = 'flex';
}

// Manual Entry Functions
function addManualWattageEntry() {
    const container = document.getElementById('manualWattageEntries');
    const entryHtml = `
        <div class="wattage-entry-row">
            <div class="wattage-input-group">
                <input type="number" class="form-input wattage-input" placeholder="Wattage (W)" min="100" max="1000">
                <input type="number" class="form-input quantity-input" placeholder="Quantity" min="1">
                <button type="button" class="btn-remove-wattage" onclick="removeManualWattageEntry(this)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', entryHtml);
    updateRemoveButtons();
}

function removeManualWattageEntry(btn) {
    btn.closest('.wattage-entry-row').remove();
    updateRemoveButtons();
}

function updateRemoveButtons() {
    const rows = document.querySelectorAll('#manualWattageEntries .wattage-entry-row');
    rows.forEach((row, index) => {
        const removeBtn = row.querySelector('.btn-remove-wattage');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'flex' : 'none';
        }
    });
}

function addManualMilestoneEntry() {
    const container = document.getElementById('manualMilestoneEntries');
    if (!container) return;
    const entryHtml = `
        <div class="milestone-entry-row-simple">
            <select class="form-input milestone-trigger-input">
                <option value="">Select trigger event</option>
                <option value="po_execution">PO Execution</option>
                <option value="shipping">Shipping</option>
                <option value="customs_cleared">Customs Clearance</option>
                <option value="project_delivery">Project Delivery</option>
            </select>
            <div class="milestone-percentage-wrapper">
                <input type="number" class="form-input milestone-percentage-input" placeholder="0" min="0" max="100" step="0.1">
                <span class="percentage-suffix">%</span>
            </div>
            <button type="button" class="btn-remove-milestone" onclick="removeManualMilestoneEntry(this)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', entryHtml);
    updateMilestoneRemoveButtons();
}

function removeManualMilestoneEntry(button) {
    button.closest('.milestone-entry-row').remove();
    updateMilestoneRemoveButtons();
}

function updateMilestoneRemoveButtons() {
    const rows = document.querySelectorAll('#manualMilestoneEntries .milestone-entry-row-simple');
    rows.forEach(row => {
        const removeBtn = row.querySelector('.btn-remove-milestone');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'flex' : 'none';
        }
    });
}

function resetManualEntryForm() {
    document.getElementById('manualManufacturerId').value = '';
    const locationSelect = document.getElementById('manualLocationId');
    locationSelect.innerHTML = '<option value="">Select a manufacturer first</option>';
    locationSelect.disabled = true;
    document.getElementById('manualModsPerPallet').value = '30';
    document.getElementById('manualPalletsPerTruck').value = '20';
    document.getElementById('manualCostPerWatt').value = '';

    // Reset wattage entries to single row
    document.getElementById('manualWattageEntries').innerHTML = `
        <div class="wattage-entry-row">
            <div class="wattage-input-group">
                <input type="number" class="form-input wattage-input" placeholder="Wattage (W)" min="100" max="1000">
                <input type="number" class="form-input quantity-input" placeholder="Quantity" min="1">
                <button type="button" class="btn-remove-wattage" onclick="removeManualWattageEntry(this)" style="display: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>
    `;

    const milestoneContainer = document.getElementById('manualMilestoneEntries');
    if (milestoneContainer) {
        milestoneContainer.innerHTML = `
            <div class="milestone-entry-row-simple">
                <select class="form-input milestone-trigger-input">
                    <option value="">Select trigger event</option>
                    <option value="po_execution">PO Execution</option>
                    <option value="shipping">Shipping</option>
                    <option value="customs_cleared">Customs Clearance</option>
                    <option value="project_delivery">Project Delivery</option>
                </select>
                <div class="milestone-percentage-wrapper">
                    <input type="number" class="form-input milestone-percentage-input" placeholder="0" min="0" max="100" step="0.1">
                    <span class="percentage-suffix">%</span>
                </div>
                <button type="button" class="btn-remove-milestone" onclick="removeManualMilestoneEntry(this)" style="display: none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
        `;
    }

    updateRemoveButtons();
    updateMilestoneRemoveButtons();
}

function addManualModuleEntry() {
    const manufacturerSelect = document.getElementById('manualManufacturerId');
    const vendorName = manufacturerSelect.options[manufacturerSelect.selectedIndex]?.text?.trim() || '';
    const locationSelect = document.getElementById('manualLocationId');
    const selectedLocationOpt = locationSelect.options[locationSelect.selectedIndex];
    const location = (selectedLocationOpt && selectedLocationOpt.value) ? (selectedLocationOpt.dataset?.address || selectedLocationOpt.text?.trim() || '') : '';
    const modsPerPallet = parseInt(document.getElementById('manualModsPerPallet').value) || 30;
    const palletsPerTruck = parseInt(document.getElementById('manualPalletsPerTruck').value) || 20;
    const costPerWatt = parseFloat(document.getElementById('manualCostPerWatt').value) || 0;

    // Validate manufacturer selection
    if (!manufacturerSelect.value || manufacturerSelect.value === 'add_new') {
        alert('Please select a manufacturer/vendor.');
        return;
    }

    // Collect wattage entries
    const wattageEntries = [];
    const rows = document.querySelectorAll('#manualWattageEntries .wattage-entry-row');
    rows.forEach(row => {
        const wattage = parseInt(row.querySelector('.wattage-input').value);
        const quantity = parseInt(row.querySelector('.quantity-input').value);
        if (wattage > 0 && quantity > 0) {
            wattageEntries.push({ wattage, quantity });
        }
    });

    if (wattageEntries.length === 0) {
        alert('Please enter at least one wattage and quantity.');
        return;
    }

    // Collect milestone entries (simplified - no name, just trigger and percentage)
    const milestones = [];
    document.querySelectorAll('#manualMilestoneEntries .milestone-entry-row-simple').forEach(row => {
        const trigger = row.querySelector('.milestone-trigger-input')?.value;
        const percentage = parseFloat(row.querySelector('.milestone-percentage-input')?.value) || 0;
        if (trigger && percentage > 0) {
            // Generate a default name from the trigger
            const triggerLabels = {
                'po_execution': 'PO Execution',
                'shipping': 'Shipping',
                'customs_cleared': 'Customs Clearance',
                'project_delivery': 'Project Delivery'
            };
            milestones.push({
                milestone_name: triggerLabels[trigger] || trigger,
                trigger_event: trigger,
                percentage: percentage
            });
        }
    });

    // For manual entries, we'll add each wattage as a separate allocation
    // but group them visually under the same vendor
    wattageEntries.forEach(entry => {
        const totalModules = entry.quantity;
        const pallets = Math.ceil(totalModules / modsPerPallet);
        const totalWatts = entry.wattage * totalModules;
        const contractValue = costPerWatt > 0 ? costPerWatt * totalWatts : 0;

        // Call the parent page function to add allocation
        if (typeof addManualModuleAllocation === 'function') {
            addManualModuleAllocation({
                vendor_name: vendorName,
                manufacturer_name: vendorName,
                manufacturer_address: location,
                wattage: entry.wattage,
                quantity: totalModules,
                pallets: pallets,
                modules_per_pallet: modsPerPallet,
                pallets_per_truck: palletsPerTruck,
                cost_per_watt: costPerWatt,
                contract_value: contractValue,
                has_milestones: milestones.length > 0,
                milestones: milestones,
                is_manual: true
            });
        } else if (typeof addModuleAllocation === 'function') {
            // Fallback - add directly to working state
            const allocation = {
                module_id: 'manual_' + Date.now(),
                vendor_name: vendorName,
                manufacturer_name: vendorName,
                manufacturer_address: location,
                wattage: entry.wattage,
                quantity: totalModules,
                pallets: pallets,
                modules_per_pallet: modsPerPallet,
                pallets_per_truck: palletsPerTruck,
                cost_per_watt: costPerWatt,
                contract_value: contractValue,
                has_milestones: milestones.length > 0,
                milestones: milestones,
                is_manual: true
            };
            workingState.moduleAllocations.push(allocation);
            showToast('Manual module entry added. Remember to save!', 'success');
            if (typeof markAsUnsaved === 'function') {
                markAsUnsaved();
            }
            if (typeof renderModuleAllocations === 'function') {
                renderModuleAllocations();
            }
            if (typeof updateBadges === 'function') {
                updateBadges();
            }
        }
    });

    resetManualEntryForm();
}

function closeWattageQuantityModal() {
    document.getElementById('wattageQuantityModal').style.display = 'none';
}

function confirmModuleAllocation() {
    const batchId = document.getElementById('selectedBatchId').value;
    const wattage = document.getElementById('selectedWattage').value;
    const quantity = document.getElementById('selectedQuantity').value;

    if (!batchId || !wattage || !quantity) {
        alert('Please fill in all fields');
        return;
    }

    // Call the parent page function to add allocation
    if (typeof addModuleAllocation === 'function') {
        addModuleAllocation(batchId, wattage, quantity);
    }

    closeWattageQuantityModal();
}

function removeModuleAllocation(allocationId) {
    if (!confirm('Remove this module batch from the projection?')) {
        return;
    }

    // Call the parent page function
    if (typeof removeAllocation === 'function') {
        removeAllocation(allocationId);
    }
}

function toggleModuleItem(allocationId) {
    const moduleItem = document.querySelector(`.module-item[data-allocation-id="${allocationId}"]`);
    if (moduleItem) {
        moduleItem.classList.toggle('collapsed');
    }
}

function saveAndCollapseModuleItem(allocationId) {
    const moduleItem = document.querySelector(`.module-item[data-allocation-id="${allocationId}"]`);
    if (!moduleItem) return;

    // Get the PO execution date input
    const poDateInput = moduleItem.querySelector('.po-date-input');
    const poDate = poDateInput ? poDateInput.value : '';

    if (typeof updateModuleAllocationPoDate === 'function') {
        updateModuleAllocationPoDate(allocationId, poDate);
    }

    updateModuleHeaderBadge(allocationId, poDate);
    moduleItem.classList.add('collapsed');
    showModuleSaveSuccess(moduleItem);

    const moduleItems = Array.from(document.querySelectorAll('.module-item'));
    const currentIndex = moduleItems.findIndex(item => item.dataset.allocationId === String(allocationId));
    const nextItem = currentIndex >= 0 ? moduleItems[currentIndex + 1] : null;

    if (nextItem) {
        nextItem.classList.remove('collapsed');
        nextItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else if (typeof expandSection === 'function') {
        expandSection('logistics-plan');
        const logisticsSection = document.querySelector('[data-section="logistics-plan"]');
        if (logisticsSection) {
            logisticsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (typeof showToast === 'function') {
        showToast('Module updated. Remember to save the projection!', 'success');
    }
}

function updateModuleHeaderBadge(allocationId, poDate) {
    const moduleItem = document.querySelector(`.module-item[data-allocation-id="${allocationId}"]`);
    if (!moduleItem) return;

    const summaryStats = moduleItem.querySelector('.module-header-left');
    if (!summaryStats) return;

    // Check if badge already exists
    let badge = summaryStats.querySelector('.po-badge-sm');
    const divider = summaryStats.querySelector('.summary-divider');

    if (!poDate) {
        if (badge) {
            badge.remove();
        }
        if (divider) {
            divider.remove();
        }
        return;
    }

    if (poDate) {
        // Parse date string without timezone conversion (YYYY-MM-DD format)
        const parts = poDate.split('-');
        const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
        const formattedDate = dateObj.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });

        if (badge) {
            // Update existing badge
            badge.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                PO: ${formattedDate}
            `;
        } else {
            // Add new badge
            const dividerElement = document.createElement('span');
            dividerElement.className = 'summary-divider';
            dividerElement.innerHTML = '&bull;';

            badge = document.createElement('span');
            badge.className = 'po-badge-sm';
            badge.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                PO: ${formattedDate}
            `;

            summaryStats.appendChild(dividerElement);
            summaryStats.appendChild(badge);
        }
    }
}

function showModuleSaveSuccess(moduleItem) {
    // Brief visual feedback
    const header = moduleItem.querySelector('.module-item-header');
    if (header) {
        header.style.background = 'rgba(40, 167, 69, 0.1)';
        setTimeout(() => {
            header.style.background = '';
        }, 1000);
    }
}

// Initialize flatpickr for PO date inputs when document loads
document.addEventListener('DOMContentLoaded', function() {
    initializePoDatePickers();
    updateRemoveButtons();
    updateMilestoneRemoveButtons();
    updateAvailableBatchStates();
});

function initializePoDatePickers() {
    if (typeof flatpickr !== 'undefined') {
        document.querySelectorAll('.po-date-input').forEach(input => {
            if (!input._flatpickr) {
                flatpickr(input, {
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'F j, Y',
                    allowInput: true,
                    onChange: function(selectedDates, dateStr) {
                        const allocationId = input.dataset.allocationId;
                        if (!allocationId) return;
                        if (typeof updateModuleAllocationPoDate === 'function') {
                            updateModuleAllocationPoDate(allocationId, dateStr);
                        }
                        updateModuleHeaderBadge(allocationId, dateStr);
                        updatePoSectionStyling(input, dateStr);
                    }
                });
            }
        });
    }
}

function updatePoSectionStyling(input, dateStr) {
    const section = input.closest('.po-execution-section');
    if (!section) return;
    const isRequired = input.dataset.poRequired === 'true';
    const isFilled = !!dateStr;

    section.classList.remove('po-required', 'po-filled', 'po-optional');
    if (isFilled) {
        section.classList.add('po-filled');
    } else if (isRequired) {
        section.classList.add('po-required');
    } else {
        section.classList.add('po-optional');
    }

    // Update the header icon and text
    const header = section.querySelector('.section-header');
    if (!header) return;
    if (isFilled) {
        header.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span style="color: #28a745;">PO Execution Date</span>`;
    } else if (isRequired) {
        header.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span style="color: #dc3545;">PO Execution Date <span style="color: #dc3545;">*</span></span>`;
    } else {
        header.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6c757d" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span style="color: #6c757d;">PO Execution Date</span>`;
    }
}
</script>
