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
    <div class="module-list" id="moduleAllocationsList">
        <?php if (!empty($allocated_modules)): ?>
            <?php foreach ($allocated_modules as $alloc):
                // Get milestones for this allocation
                $milestones = $alloc['milestones'] ?? [];
                $po_execution_date = $alloc['po_execution_date'] ?? '';
                $is_collapsed = !empty($po_execution_date); // Collapse if PO date is set
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
                            <!-- PO Execution Date -->
                            <div class="po-execution-section">
                                <div class="section-header">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <span>PO Execution Date</span>
                                </div>
                                <div class="po-execution-input">
                                    <input type="text" class="form-input flatpickr-date po-date-input"
                                           data-allocation-id="<?php echo $alloc['id']; ?>"
                                           value="<?php echo htmlspecialchars($po_execution_date); ?>"
                                           placeholder="Select date when PO was executed"
                                           <?php echo $can_edit ? '' : 'disabled'; ?>>
                                </div>
                            </div>

                            <!-- Milestones Section -->
                            <div class="milestones-section">
                                <div class="section-header">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    <span>Milestone Payments</span>
                                </div>
                                <?php if (!empty($milestones)): ?>
                                    <div class="milestones-list">
                                        <?php foreach ($milestones as $milestone): ?>
                                            <div class="milestone-row">
                                                <div class="milestone-info">
                                                    <span class="milestone-name"><?php echo htmlspecialchars($milestone['milestone_name'] ?? $milestone['name'] ?? 'Milestone'); ?></span>
                                                    <span class="milestone-trigger"><?php echo htmlspecialchars($milestone['trigger_event'] ?? 'On trigger'); ?></span>
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
                                    <div class="milestones-empty">
                                        <p>No milestones configured for this batch.</p>
                                        <small>Milestones are configured in the module batch settings.</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="module-item-actions">
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="removeModuleAllocation(<?php echo $alloc['id']; ?>)">
                                    Remove Batch
                                </button>
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="saveAndCollapseModuleItem(<?php echo $alloc['id']; ?>)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg>
                                    Collapse
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
    <div class="module-selector-panel">
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
                                            onclick="selectModuleBatch(<?php echo $batch['id']; ?>, '<?php echo implode(',', $batch['wattage_list']); ?>', <?php echo $batch['total_quantity']; ?>, <?php echo $batch['modules_per_pallet'] ?? 30; ?>, <?php echo $batch['pallets_per_truck'] ?? 20; ?>)">
                                        Select
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
                        <input type="text" id="manualVendorName" class="form-input" list="manufacturerOptions" placeholder="e.g., JA Solar, Longi, etc.">
                        <datalist id="manufacturerOptions">
                            <?php foreach ($manufacturer_names ?? [] as $name): ?>
                                <option value="<?php echo htmlspecialchars($name); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Location/Origin</label>
                        <div class="address-input-wrapper" id="manualLocationWrapper">
                        <input type="text" id="manualLocation" class="form-input" list="manufacturerLocationOptions" placeholder="Start typing to search for address...">
                            <div class="address-loading"></div>
                            <svg class="address-verified" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <div class="address-error">Please select a valid address from the suggestions</div>
                        </div>
                        <datalist id="manufacturerLocationOptions">
                            <?php foreach ($manufacturer_locations ?? [] as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
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
                    <label class="form-label">Milestones (optional)</label>
                    <div id="manualMilestoneEntries">
                        <div class="milestone-entry-row">
                            <input type="text" class="form-input milestone-name-input" placeholder="Milestone name">
                            <select class="form-input milestone-trigger-input">
                                <option value="">Trigger event</option>
                                <option value="po_execution">PO Execution</option>
                                <option value="shipping">Shipping</option>
                                <option value="customs_cleared">Customs Clearance</option>
                                <option value="project_delivery">Project Delivery</option>
                            </select>
                            <input type="number" class="form-input milestone-percentage-input" placeholder="%" min="0" max="100" step="0.1">
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

                <div class="btn-group">
                    <button type="button" class="btn btn-primary" onclick="addManualModuleEntry()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px;">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add to Projection
                    </button>
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
<?php endif; ?>
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
    max-width: 700px;
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
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.05) 0%, rgba(58, 110, 127, 0.05) 100%);
    border: 1px solid rgba(72, 140, 154, 0.2);
    border-radius: 12px;
    padding: 20px;
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
</style>

<script>
const manufacturerLocationMap = <?php echo json_encode($manufacturer_location_map); ?>;
const manufacturerLocationOptions = <?php echo json_encode($manufacturer_locations); ?>;

function updateManualLocationOptions(vendorName) {
    const datalist = document.getElementById('manufacturerLocationOptions');
    if (!datalist) return;

    const locations = manufacturerLocationMap[vendorName] || manufacturerLocationOptions;
    datalist.innerHTML = '';
    locations.forEach(location => {
        const option = document.createElement('option');
        option.value = location;
        datalist.appendChild(option);
    });
}

function initializeManualLocationAutocomplete() {
    const input = document.getElementById('manualLocation');
    if (!input || typeof initializeAddressAutocomplete !== 'function') {
        return;
    }
    if (input.dataset.autocompleteInitialized) {
        return;
    }

    const autocomplete = initializeAddressAutocomplete(input, (placeData) => {
        if (placeData?.address) {
            input.value = placeData.address;
        }
    });

    input.dataset.autocompleteInitialized = 'true';
    if (autocomplete && Array.isArray(window.autocompleteInstances)) {
        window.autocompleteInstances.push(autocomplete);
    }
}

function bindManualManufacturerEvents() {
    const vendorInput = document.getElementById('manualVendorName');
    if (!vendorInput) return;

    vendorInput.addEventListener('input', () => {
        updateManualLocationOptions(vendorInput.value.trim());
    });

    updateManualLocationOptions(vendorInput.value.trim());
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

function selectModuleBatch(batchId, wattages, totalQuantity, modsPerPallet, palletsPerTruck) {
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
        <div class="milestone-entry-row">
            <input type="text" class="form-input milestone-name-input" placeholder="Milestone name">
            <select class="form-input milestone-trigger-input">
                <option value="">Trigger event</option>
                <option value="po_execution">PO Execution</option>
                <option value="shipping">Shipping</option>
                <option value="customs_cleared">Customs Clearance</option>
                <option value="project_delivery">Project Delivery</option>
            </select>
            <input type="number" class="form-input milestone-percentage-input" placeholder="%" min="0" max="100" step="0.1">
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
    const rows = document.querySelectorAll('#manualMilestoneEntries .milestone-entry-row');
    rows.forEach(row => {
        const removeBtn = row.querySelector('.btn-remove-milestone');
        if (removeBtn) {
            removeBtn.style.display = rows.length > 1 ? 'flex' : 'none';
        }
    });
}

function resetManualEntryForm() {
    document.getElementById('manualVendorName').value = '';
    document.getElementById('manualLocation').value = '';
    document.getElementById('manualModsPerPallet').value = '30';
    document.getElementById('manualPalletsPerTruck').value = '20';
    document.getElementById('manualCostPerWatt').value = '';

    const manualLocationWrapper = document.getElementById('manualLocationWrapper');
    if (manualLocationWrapper) {
        manualLocationWrapper.classList.remove('verified', 'error', 'loading');
    }

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
            <div class="milestone-entry-row">
                <input type="text" class="form-input milestone-name-input" placeholder="Milestone name">
                <select class="form-input milestone-trigger-input">
                    <option value="">Trigger event</option>
                    <option value="po_execution">PO Execution</option>
                    <option value="shipping">Shipping</option>
                    <option value="customs_cleared">Customs Clearance</option>
                    <option value="project_delivery">Project Delivery</option>
                </select>
                <input type="number" class="form-input milestone-percentage-input" placeholder="%" min="0" max="100" step="0.1">
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
    updateManualLocationOptions('');
}

function addManualModuleEntry() {
    const vendorName = document.getElementById('manualVendorName').value.trim();
    const location = document.getElementById('manualLocation').value.trim();
    const modsPerPallet = parseInt(document.getElementById('manualModsPerPallet').value) || 30;
    const palletsPerTruck = parseInt(document.getElementById('manualPalletsPerTruck').value) || 20;
    const costPerWatt = parseFloat(document.getElementById('manualCostPerWatt').value) || 0;

    // Validate vendor name
    if (!vendorName) {
        alert('Please enter a manufacturer/vendor name.');
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

    // Collect milestone entries
    const milestones = [];
    document.querySelectorAll('#manualMilestoneEntries .milestone-entry-row').forEach(row => {
        const name = row.querySelector('.milestone-name-input')?.value.trim();
        const trigger = row.querySelector('.milestone-trigger-input')?.value;
        const percentage = parseFloat(row.querySelector('.milestone-percentage-input')?.value) || 0;
        if (trigger && percentage > 0) {
            milestones.push({
                milestone_name: name,
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
        const formattedDate = new Date(poDate).toLocaleDateString('en-US', {
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
    initializeManualLocationAutocomplete();
    bindManualManufacturerEvents();
    updateRemoveButtons();
    updateMilestoneRemoveButtons();
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
                    }
                });
            }
        });
    }
}
</script>
