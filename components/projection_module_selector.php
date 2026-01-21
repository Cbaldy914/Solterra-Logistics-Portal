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

$total_allocated_value = 0;
$total_allocated_modules = 0;

if (!empty($allocated_modules)) {
    foreach ($allocated_modules as $alloc) {
        $total_allocated_value += $alloc['contract_value'] ?? 0;
        $total_allocated_modules += $alloc['quantity'] ?? 0;
    }
}
?>

<div class="module-selector-card">
    <div class="card-header">
        <div class="card-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                <line x1="8" y1="21" x2="16" y2="21"/>
                <line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
            Modules Included
        </div>
        <div class="card-summary">
            <span class="summary-value"><?php echo number_format($total_allocated_modules); ?></span>
            <span class="summary-label">modules</span>
            <span class="summary-divider">|</span>
            <span class="summary-value">$<?php echo number_format($total_allocated_value, 2); ?></span>
            <span class="summary-label">contract value</span>
        </div>
    </div>

    <div class="module-list" id="allocatedModuleList">
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
                        <div class="module-header-info">
                            <div class="module-vendor">
                                <?php echo htmlspecialchars($alloc['vendor_name'] ?? 'Unknown Vendor'); ?>
                                <?php if (!empty($alloc['manufacturer_name'])): ?>
                                    <span class="manufacturer-tag"><?php echo htmlspecialchars($alloc['manufacturer_name']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="module-summary-stats">
                                <span class="summary-stat">
                                    <strong><?php echo number_format($alloc['wattage']); ?>W</strong>
                                </span>
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat">
                                    <?php echo number_format($alloc['quantity']); ?> modules
                                </span>
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat">
                                    <?php echo number_format($alloc['pallets'] ?? 0); ?> pallets
                                </span>
                                <span class="summary-divider">&bull;</span>
                                <span class="summary-stat">
                                    <strong>$<?php echo number_format($alloc['contract_value'] ?? 0, 2); ?></strong>
                                </span>
                                <?php if ($po_execution_date): ?>
                                    <span class="summary-divider">&bull;</span>
                                    <span class="po-badge-sm">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                        PO: <?php echo date('M j, Y', strtotime($po_execution_date)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="module-header-toggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                    </div>

                    <!-- Expanded Content -->
                    <div class="module-item-body">
                        <div class="module-info">
                            <div class="module-details">
                                <span class="detail-item">
                                    <strong><?php echo number_format($alloc['wattage']); ?>W</strong>
                                </span>
                                <span class="detail-divider">&bull;</span>
                                <span class="detail-item">
                                    <?php echo number_format($alloc['quantity']); ?> modules
                                </span>
                                <span class="detail-divider">&bull;</span>
                                <span class="detail-item">
                                    <?php echo number_format($alloc['pallets'] ?? 0); ?> pallets
                                </span>
                            </div>
                            <div class="module-costs">
                                <span class="cost-contract">
                                    Contract Value: <strong>$<?php echo number_format($alloc['contract_value'] ?? 0, 2); ?></strong>
                                </span>
                            </div>
                        </div>

                        <!-- PO Execution Date -->
                        <div class="po-execution-section">
                            <div class="po-execution-header">
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
                            <div class="milestones-header">
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
                                    Save & Collapse
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
    <div class="add-module-section">
        <button type="button" class="btn btn-add-module" onclick="openModuleSelectorModal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Module Batch
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Module Selector Modal -->
<?php if ($can_edit): ?>
<div id="moduleSelectorModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeModuleSelectorModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>Select Module Batch</h3>
            <button type="button" class="modal-close" onclick="closeModuleSelectorModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <?php if (!empty($available_batches)): ?>
                <div class="batch-list">
                    <?php foreach ($available_batches as $batch): ?>
                        <div class="batch-item" data-batch-id="<?php echo $batch['id']; ?>">
                            <div class="batch-info">
                                <div class="batch-vendor"><?php echo htmlspecialchars($batch['vendor_name']); ?></div>
                                <div class="batch-manufacturer">
                                    <?php echo htmlspecialchars($batch['manufacturer_name'] ?? 'Unknown manufacturer'); ?>
                                    <?php if (!empty($batch['manufacturer_country'])): ?>
                                        - <?php echo htmlspecialchars($batch['manufacturer_country']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="batch-details">
                                    <span>Wattages: <?php echo htmlspecialchars($batch['wattages']); ?>W</span>
                                    <span>&bull;</span>
                                    <span>Total: <?php echo number_format($batch['total_quantity']); ?> modules</span>
                                    <?php if ($batch['has_milestones']): ?>
                                        <span class="milestone-badge-sm">Milestones configured</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="batch-action">
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="selectModuleBatch(<?php echo $batch['id']; ?>, '<?php echo implode(',', $batch['wattage_list']); ?>', <?php echo $batch['total_quantity']; ?>)">
                                    Select
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>No module batches available for this project.</p>
                    <p><small>Create module batches in the Project Overview to add them here.</small></p>
                </div>
            <?php endif; ?>
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

/* Collapsible Module Item Styles */
.module-item {
    flex-direction: column;
    padding: 0;
    overflow: hidden;
}

.module-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    cursor: pointer;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    transition: all 0.2s ease;
}

.module-item-header:hover {
    background: #f0f4f5;
}

.module-item.collapsed .module-item-header {
    background: white;
    border-bottom: none;
}

.module-header-info {
    flex: 1;
}

.module-header-toggle {
    padding: 4px;
    color: #6c757d;
    transition: transform 0.3s ease;
}

.module-item.collapsed .module-header-toggle {
    transform: rotate(-90deg);
}

.module-summary-stats {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 0.85em;
    color: #6c757d;
    margin-top: 6px;
    flex-wrap: wrap;
}

.summary-stat {
    color: #495057;
}

.summary-stat strong {
    color: #293E4C;
}

.po-badge-sm {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #d4edda;
    color: #155724;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 500;
}

.po-badge-sm svg {
    color: #28a745;
}

.module-item-body {
    padding: 20px;
    background: white;
    transition: all 0.3s ease;
}

.module-item.collapsed .module-item-body {
    display: none;
}

/* PO Execution Section */
.po-execution-section {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.05) 0%, rgba(58, 110, 127, 0.05) 100%);
    border: 1px solid rgba(72, 140, 154, 0.2);
    border-radius: 12px;
    padding: 16px;
    margin-top: 16px;
}

.po-execution-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 12px;
}

.po-execution-input .form-input {
    background: white;
}

/* Milestones Section */
.milestones-section {
    margin-top: 16px;
    border-top: 1px solid #e9ecef;
    padding-top: 16px;
}

.milestones-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: #293E4C;
    margin-bottom: 12px;
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
    padding: 10px 14px;
    background: #f8f9fa;
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
    font-size: 0.95em;
}

.milestones-empty {
    padding: 16px;
    background: #f8f9fa;
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
    justify-content: flex-end;
    gap: 12px;
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
function openModuleSelectorModal() {
    document.getElementById('moduleSelectorModal').style.display = 'flex';
}

function closeModuleSelectorModal() {
    document.getElementById('moduleSelectorModal').style.display = 'none';
}

function selectModuleBatch(batchId, wattages, totalQuantity) {
    closeModuleSelectorModal();

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

    // Show wattage/quantity modal
    document.getElementById('wattageQuantityModal').style.display = 'flex';
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

    // Save the PO execution date via AJAX
    if (poDate) {
        const formData = new FormData();
        formData.append('action', 'save_po_execution_date');
        formData.append('allocation_id', allocationId);
        formData.append('po_execution_date', poDate);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the header badge
                updateModuleHeaderBadge(allocationId, poDate);

                // Collapse the item
                moduleItem.classList.add('collapsed');

                // Show success feedback
                showModuleSaveSuccess(moduleItem);
            } else {
                alert('Error saving PO date: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Still collapse even if save fails (might be handled elsewhere)
            moduleItem.classList.add('collapsed');
        });
    } else {
        // No date to save, just collapse
        moduleItem.classList.add('collapsed');
    }
}

function updateModuleHeaderBadge(allocationId, poDate) {
    const moduleItem = document.querySelector(`.module-item[data-allocation-id="${allocationId}"]`);
    if (!moduleItem) return;

    const summaryStats = moduleItem.querySelector('.module-summary-stats');
    if (!summaryStats) return;

    // Check if badge already exists
    let badge = summaryStats.querySelector('.po-badge-sm');

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
            const divider = document.createElement('span');
            divider.className = 'summary-divider';
            divider.innerHTML = '&bull;';

            badge = document.createElement('span');
            badge.className = 'po-badge-sm';
            badge.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                PO: ${formattedDate}
            `;

            summaryStats.appendChild(divider);
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
});

function initializePoDatePickers() {
    if (typeof flatpickr !== 'undefined') {
        document.querySelectorAll('.po-date-input').forEach(input => {
            if (!input._flatpickr) {
                flatpickr(input, {
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'F j, Y',
                    allowInput: true
                });
            }
        });
    }
}
</script>
