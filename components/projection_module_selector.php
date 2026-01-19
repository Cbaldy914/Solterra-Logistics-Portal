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
            <?php foreach ($allocated_modules as $alloc): ?>
                <div class="module-item" data-allocation-id="<?php echo $alloc['id']; ?>">
                    <div class="module-info">
                        <div class="module-vendor">
                            <?php echo htmlspecialchars($alloc['vendor_name'] ?? 'Unknown Vendor'); ?>
                            <?php if (!empty($alloc['manufacturer_name'])): ?>
                                <span class="manufacturer-tag"><?php echo htmlspecialchars($alloc['manufacturer_name']); ?></span>
                            <?php endif; ?>
                        </div>
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
                                Contract: <strong>$<?php echo number_format($alloc['contract_value'] ?? 0, 2); ?></strong>
                            </span>
                            <?php if ($alloc['has_milestones']): ?>
                                <span class="milestone-indicator" title="Milestones configured">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    Milestones set
                                </span>
                            <?php else: ?>
                                <span class="milestone-indicator milestone-warning" title="No milestones configured">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ffc107" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="12" y1="8" x2="12" y2="12"/>
                                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                                    </svg>
                                    No milestones
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($can_edit): ?>
                    <div class="module-actions">
                        <button type="button" class="btn-remove-module"
                                onclick="removeModuleAllocation(<?php echo $alloc['id']; ?>)"
                                title="Remove from projection">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <?php endif; ?>
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
</script>
