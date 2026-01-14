<?php
/**
 * Project Overview Modals Component
 * Contains all modal dialogs for the project overview page
 *
 * Required variables from parent:
 * - $role: User role (admin, global_admin, customer_admin, user)
 * - $project_id: Current project ID
 */

// Only show admin modals for admin roles
if (in_array($role, ['admin', 'global_admin', 'customer_admin'])):
?>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="delete-modal">
    <div class="delete-modal-content">
        <h3>Confirm Project Deletion</h3>
        <p id="deleteModalText">Are you sure you want to delete this project? This action cannot be undone.</p>
        <div class="modal-buttons">
            <button class="modal-btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button class="modal-btn btn-delete" onclick="confirmDelete()">Delete Project</button>
        </div>
    </div>
</div>

<!-- Add Module Modal -->
<div id="addModuleModal" class="add-module-modal">
    <div class="add-module-modal-content">
        <h3>Add New Module Batch</h3>
        <form id="addModuleForm">
            <div class="modal-form-grid">
                <div class="modal-form-group">
                    <label for="modal_manufacturer_id">Manufacturer:</label>
                    <select id="modal_manufacturer_id" name="manufacturer_id">
                        <option value="">Select Manufacturer</option>
                    </select>
                </div>

                <div class="modal-form-group">
                    <label for="modal_vendor_name">Vendor Name:</label>
                    <input type="text" id="modal_vendor_name" name="vendor_name" required>
                </div>

                <div class="modal-form-group">
                    <label for="modal_initial_location">Location:</label>
                    <input type="text" id="modal_initial_location" name="initial_location" required>
                </div>

                <div class="modal-form-group">
                    <label for="modal_modules_per_pallet">Modules per Pallet:</label>
                    <input type="number" id="modal_modules_per_pallet" name="modules_per_pallet" min="1">
                </div>

                <div class="wattage-section">
                    <h4>Module Wattages & Quantities</h4>
                    <div id="modal_wattage_container">
                        <!-- Wattage entries will be added here -->
                    </div>
                    <button type="button" class="add-wattage-btn" onclick="addModalWattageField()">+ Add Wattage</button>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="button" class="modal-btn btn-secondary" onclick="closeAddModuleModal()">Cancel</button>
                <button type="submit" class="modal-btn btn-primary">Add Module Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Batch Modal -->
<div id="editBatchModal" class="add-module-modal">
    <div class="add-module-modal-content">
        <h3 id="editBatchModalTitle">Edit Module Batch</h3>
        <form id="editBatchForm">
            <input type="hidden" id="edit_batch_id" name="batch_id">
            <div class="wattage-section">
                <h4>Module Wattages & Quantities</h4>
                <div id="edit_wattage_container">
                    <!-- Existing wattage entries will be loaded here -->
                </div>
                <button type="button" class="add-wattage-btn" onclick="addEditWattageField()">+ Add Wattage</button>
            </div>

            <div class="modal-buttons">
                <button type="button" class="modal-btn btn-secondary" onclick="closeEditBatchModal()">Cancel</button>
                <button type="submit" class="modal-btn btn-primary">Update Module Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Shipping Modal -->
<div id="shippingModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="shippingModalTitle"></h3>
            <span class="close-modal" onclick="closeShippingModal()">&times;</span>
        </div>
        <div class="modal-body" id="shippingModalContent"></div>
    </div>
</div>

<?php endif; ?>

<!-- Logistics Breakdown Modal (available to all users) -->
<style>
.logistics-modal-content {
    background: #fff;
    border-radius: 16px;
    width: 90%;
    max-width: 400px;
    margin: 10% auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
}
.logistics-modal-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    padding: 28px 32px;
    position: relative;
}
.logistics-modal-header h3 {
    color: #fff;
    margin: 0 0 6px 0;
    font-size: 0.9em;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.85;
}
.logistics-modal-total {
    color: #fff;
    font-size: 2.4em;
    font-weight: 700;
    letter-spacing: -0.5px;
}
.logistics-modal-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 24px;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
    border-radius: 50%;
    background: transparent;
}
.logistics-modal-close:hover {
    opacity: 1;
    background: rgba(255,255,255,0.15);
}
.logistics-modal-body {
    padding: 24px 32px 32px 32px;
}
.logistics-breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid #eee;
}
.logistics-breakdown-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.logistics-breakdown-item:first-child {
    padding-top: 0;
}
.logistics-breakdown-label {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #293E4C;
    font-weight: 500;
    font-size: 0.95em;
}
.logistics-breakdown-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.logistics-breakdown-dot.freight { background: #3b82f6; }
.logistics-breakdown-dot.warehousing { background: #8b5cf6; }
.logistics-breakdown-dot.accessorial { background: #f59e0b; }
.logistics-breakdown-dot.solterra { background: #10b981; }
.logistics-breakdown-value {
    font-weight: 600;
    color: #293E4C;
    font-size: 1em;
}
</style>

<div id="logisticsBreakdownModal" class="warehouse-selection-modal" style="display:none;">
    <div class="logistics-modal-content">
        <div class="logistics-modal-header">
            <span class="logistics-modal-close" onclick="closeLogisticsBreakdownModal()">&times;</span>
            <h3>Total Logistics Cost</h3>
            <div class="logistics-modal-total">$<?php echo number_format($total_logistics_cost ?? 0, 2); ?></div>
        </div>
        <div class="logistics-modal-body">
            <div class="logistics-breakdown-item">
                <span class="logistics-breakdown-label">
                    <span class="logistics-breakdown-dot freight"></span>
                    Freight
                </span>
                <span class="logistics-breakdown-value">$<?php echo number_format($total_freight_cost ?? 0, 2); ?></span>
            </div>
            <div class="logistics-breakdown-item">
                <span class="logistics-breakdown-label">
                    <span class="logistics-breakdown-dot warehousing"></span>
                    Warehousing
                </span>
                <span class="logistics-breakdown-value">$<?php echo number_format($total_warehousing_cost ?? 0, 2); ?></span>
            </div>
            <div class="logistics-breakdown-item">
                <span class="logistics-breakdown-label">
                    <span class="logistics-breakdown-dot accessorial"></span>
                    Accessorial
                </span>
                <span class="logistics-breakdown-value">$<?php echo number_format($total_accessorial_costs ?? 0, 2); ?></span>
            </div>
            <?php if (($total_solterra_fee ?? 0) > 0): ?>
            <div class="logistics-breakdown-item">
                <span class="logistics-breakdown-label">
                    <span class="logistics-breakdown-dot solterra"></span>
                    Solterra Fee
                </span>
                <span class="logistics-breakdown-value">$<?php echo number_format($total_solterra_fee, 2); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function openLogisticsBreakdownModal() {
    document.getElementById('logisticsBreakdownModal').style.display = 'block';
}
function closeLogisticsBreakdownModal() {
    document.getElementById('logisticsBreakdownModal').style.display = 'none';
}
document.getElementById('logisticsBreakdownModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeLogisticsBreakdownModal();
});
</script>
