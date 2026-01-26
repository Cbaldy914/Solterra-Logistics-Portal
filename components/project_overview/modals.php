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
    max-width: 420px;
    margin: 8% auto;
    box-shadow: 0 24px 80px rgba(0,0,0,0.2), 0 8px 24px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
}
.logistics-modal-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    padding: 32px 36px;
    position: relative;
}
.logistics-modal-header h3 {
    color: #fff;
    margin: 0 0 8px 0;
    font-size: 0.85em;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    opacity: 0.8;
}
.logistics-modal-total {
    color: #fff;
    font-size: 2.4em;
    font-weight: 700;
    letter-spacing: -0.5px;
}
.logistics-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
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
    padding: 28px 36px 36px 36px;
}
.logistics-breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid #f0f2f4;
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
    gap: 14px;
    color: #293E4C;
    font-weight: 500;
    font-size: 0.95em;
}
.logistics-breakdown-dot {
    width: 12px;
    height: 12px;
    border-radius: 4px;
    flex-shrink: 0;
}
.logistics-breakdown-dot.freight { background: #3b82f6; }
.logistics-breakdown-dot.warehousing { background: #8b5cf6; }
.logistics-breakdown-dot.accessorial { background: #f59e0b; }
.logistics-breakdown-dot.solterra { background: #10b981; }
.logistics-breakdown-value {
    font-weight: 600;
    color: #293E4C;
    font-size: 1.05em;
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

<!-- Total Cost Breakdown Modal -->
<style>
.total-cost-modal-content {
    background: #fff;
    border-radius: 18px;
    width: 92%;
    max-width: 800px;
    margin: 6% auto;
    box-shadow: 0 24px 80px rgba(0,0,0,0.2), 0 8px 24px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
    max-height: 86vh;
    display: flex;
    flex-direction: column;
}
.total-cost-modal-header {
    background: #488C9A;
    padding: 26px 32px;
    position: relative;
    flex-shrink: 0;
}
.total-cost-modal-header h3 {
    color: #fff;
    margin: 0 0 6px 0;
    font-size: 1.1em;
    font-weight: 600;
}
.total-cost-modal-total {
    color: #fff;
    font-size: 2.1em;
    font-weight: 700;
    letter-spacing: -0.5px;
}
.total-cost-modal-subtitle {
    color: rgba(255,255,255,0.75);
    font-size: 0.85em;
    margin-top: 6px;
}
.total-cost-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
    border-radius: 50%;
    background: transparent;
}
.total-cost-modal-close:hover {
    opacity: 1;
    background: rgba(255,255,255,0.15);
}
.total-cost-modal-body {
    padding: 24px 32px 32px 32px;
    overflow-y: auto;
    flex: 1;
}
.total-cost-sections {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 50px;
}
.total-cost-sections .total-cost-section {
    min-width: 0;
}
@media (max-width: 768px) {
    .total-cost-sections {
        grid-template-columns: 1fr;
    }
}
.total-cost-section {
    margin-top: 0;
}
.total-cost-section-title {
    font-size: 0.78em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6c757d;
    margin-bottom: 14px;
}
</style>

<div id="totalCostBreakdownModal" class="warehouse-selection-modal" style="display:none;">
    <div class="total-cost-modal-content">
        <div class="total-cost-modal-header">
            <span class="total-cost-modal-close" onclick="closeTotalCostBreakdownModal()">&times;</span>
            <h3>Total Costs to Date</h3>
            <div class="total-cost-modal-total">$<?php echo number_format($total_project_cost ?? 0, 2); ?></div>
            <div class="total-cost-modal-subtitle">Accrued milestones and logistics costs</div>
        </div>
        <div class="total-cost-modal-body">
            <div class="total-cost-sections">
                <div class="total-cost-section">
                    <div class="total-cost-section-title">Milestone Accrual</div>
                    <?php if (!empty($module_milestone_rows)):
                        foreach ($module_milestone_rows as $msr):
                            if ($msr['percentage'] <= 0) continue;
                    ?>
                    <div class="milestone-row">
                        <div class="milestone-row-left">
                            <div class="milestone-status-dot <?php echo $msr['is_complete'] ? 'complete' : 'pending'; ?>"></div>
                            <div>
                                <span class="milestone-row-name"><?php echo htmlspecialchars($msr['name']); ?></span>
                                <span class="milestone-row-pct">(<?php echo $msr['percentage']; ?>%)</span>
                            </div>
                        </div>
                        <div class="milestone-row-right">
                            <div class="milestone-row-accrued">$<?php echo number_format($msr['accrued_amount'], 2); ?></div>
                            <div class="milestone-row-target">of $<?php echo number_format($msr['target_amount'], 2); ?></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div style="text-align:center; padding:18px; color:#6c757d;">No milestone data configured</div>
                    <?php endif; ?>

                    <div class="module-breakdown-footer">
                        <div class="module-breakdown-footer-row">
                            <span class="module-breakdown-footer-label">Accrued Module Cost</span>
                            <span class="module-breakdown-footer-value">$<?php echo number_format($accrued_module_cost ?? 0, 2); ?></span>
                        </div>
                        <div class="module-breakdown-footer-row">
                            <span class="module-breakdown-footer-label">Full Contract Value</span>
                            <span class="module-breakdown-footer-value">$<?php echo number_format($module_contract_value ?? 0, 2); ?></span>
                        </div>
                    </div>
                </div>

                <div class="total-cost-section">
                    <div class="total-cost-section-title">Logistics Costs to Date</div>
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
                    <div class="logistics-breakdown-item">
                        <span class="logistics-breakdown-label">Total Logistics</span>
                        <span class="logistics-breakdown-value">$<?php echo number_format($total_logistics_cost ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openTotalCostBreakdownModal() {
    document.getElementById('totalCostBreakdownModal').style.display = 'block';
}
function closeTotalCostBreakdownModal() {
    document.getElementById('totalCostBreakdownModal').style.display = 'none';
}
document.getElementById('totalCostBreakdownModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeTotalCostBreakdownModal();
});
</script>

<!-- Cashflow Forecast Detail Modal -->
<style>
.cashflow-modal-content {
    background: #fff;
    border-radius: 16px;
    width: 92%;
    max-width: 720px;
    margin: 4% auto;
    box-shadow: 0 24px 80px rgba(0,0,0,0.2), 0 8px 24px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
}
.cashflow-modal-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    padding: 28px 32px;
    position: relative;
    flex-shrink: 0;
}
.cashflow-modal-header h3 {
    color: #fff;
    margin: 0;
    font-size: 1.15em;
    font-weight: 600;
    letter-spacing: -0.2px;
}
.cashflow-modal-header .modal-subtitle {
    color: rgba(255,255,255,0.75);
    font-size: 0.85em;
    margin-top: 4px;
}
.cashflow-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
    border-radius: 50%;
    background: transparent;
}
.cashflow-modal-close:hover {
    opacity: 1;
    background: rgba(255,255,255,0.15);
}
.cashflow-modal-body {
    padding: 0;
    overflow-y: auto;
    flex: 1;
}
.cashflow-detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88em;
}
.cashflow-detail-table thead th {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, #f8f9fa 0%, #f0f4f5 100%);
    padding: 14px 16px;
    font-weight: 600;
    color: #293E4C;
    text-align: right;
    border-bottom: 2px solid #e9ecef;
    white-space: nowrap;
    text-transform: uppercase;
    font-size: 0.78em;
    letter-spacing: 0.5px;
    z-index: 1;
}
.cashflow-detail-table thead th:first-child {
    text-align: left;
}
.cashflow-detail-table tbody td {
    padding: 13px 16px;
    border-bottom: 1px solid #f1f3f4;
    text-align: right;
    color: #495057;
    font-weight: 500;
}
.cashflow-detail-table tbody td:first-child {
    text-align: left;
    font-weight: 500;
    color: #293E4C;
    font-size: 0.9em;
}
.cashflow-detail-table tbody tr:hover {
    background: rgba(72, 140, 154, 0.04);
}
.cashflow-detail-table tbody tr:last-child td {
    border-bottom: none;
}
.cashflow-detail-table .amount-freight { color: #488C9A; }
.cashflow-detail-table .amount-warehousing { color: #E07F3A; }
.cashflow-detail-table .amount-milestone { color: #28a745; }
.cashflow-detail-table .weekly-total-val {
    font-weight: 600;
    color: #293E4C;
}
.cashflow-detail-table .cumulative-val {
    font-weight: 700;
    color: #488C9A;
}
.cashflow-modal-footer {
    padding: 16px 24px;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    flex-shrink: 0;
}
.cashflow-modal-footer .footer-stat {
    text-align: right;
}
.cashflow-modal-footer .footer-stat-label {
    font-size: 0.75em;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cashflow-modal-footer .footer-stat-value {
    font-size: 1.1em;
    font-weight: 700;
    color: #293E4C;
}
</style>

<div id="cashflowDetailModal" class="warehouse-selection-modal" style="display:none;">
    <div class="cashflow-modal-content">
        <div class="cashflow-modal-header">
            <span class="cashflow-modal-close" onclick="closeCashflowDetailModal()">&times;</span>
            <h3>Cashflow Forecast Detail</h3>
            <div class="modal-subtitle">Weekly cost projections from primary logistics plan</div>
        </div>
        <div class="cashflow-modal-body">
            <table class="cashflow-detail-table">
                <thead>
                    <tr>
                        <th>Date Range</th>
                        <th>Freight</th>
                        <th>Warehousing</th>
                        <th>Milestones</th>
                        <th>Weekly Total</th>
                        <th>Cumulative</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($all_projection_weeks)):
                        foreach ($all_projection_weeks as $pw):
                            $ws = new DateTime($pw['week_start']);
                            $we = clone $ws;
                            $we->modify('+6 days');
                            $date_range = $ws->format('M j') . ' - ' . $we->format('M j, Y');
                    ?>
                    <tr>
                        <td><?php echo $date_range; ?></td>
                        <td class="amount-freight">$<?php echo number_format($pw['freight'], 0); ?></td>
                        <td class="amount-warehousing">$<?php echo number_format($pw['warehousing'], 0); ?></td>
                        <td class="amount-milestone">$<?php echo number_format($pw['milestones'], 0); ?></td>
                        <td class="weekly-total-val">$<?php echo number_format($pw['total'], 0); ?></td>
                        <td class="cumulative-val">$<?php echo number_format($pw['cumulative'], 0); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:40px; color:#6c757d; font-style:italic;">No projection data available. Create a logistics plan to see forecasted costs.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($all_projection_weeks)):
            $last_week = end($all_projection_weeks);
        ?>
        <div class="cashflow-modal-footer">
            <div class="footer-stat">
                <div class="footer-stat-label">Projected Total</div>
                <div class="footer-stat-value">$<?php echo number_format($last_week['cumulative'], 0); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function openCashflowDetailModal() {
    document.getElementById('cashflowDetailModal').style.display = 'block';
}
function closeCashflowDetailModal() {
    document.getElementById('cashflowDetailModal').style.display = 'none';
}
document.getElementById('cashflowDetailModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCashflowDetailModal();
});
</script>

<!-- Module Cost Breakdown Modal -->
<style>
.module-breakdown-modal-content {
    background: #fff;
    border-radius: 16px;
    width: 90%;
    max-width: 480px;
    margin: 6% auto;
    box-shadow: 0 24px 80px rgba(0,0,0,0.2), 0 8px 24px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
}
.module-breakdown-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    padding: 32px 36px;
    position: relative;
}
.module-breakdown-header h3 {
    color: #fff;
    margin: 0 0 8px 0;
    font-size: 0.85em;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    opacity: 0.8;
}
.module-breakdown-total {
    color: #fff;
    font-size: 2.2em;
    font-weight: 700;
    letter-spacing: -0.5px;
}
.module-breakdown-subtitle {
    color: rgba(255,255,255,0.75);
    font-size: 0.85em;
    margin-top: 6px;
}
.module-breakdown-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
    border-radius: 50%;
    background: transparent;
}
.module-breakdown-close:hover {
    opacity: 1;
    background: rgba(255,255,255,0.15);
}
.module-breakdown-body {
    padding: 28px 36px 32px 36px;
}
.milestone-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid #f0f2f4;
}
.milestone-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.milestone-row:first-child {
    padding-top: 0;
}
.milestone-row-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.milestone-status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.milestone-status-dot.complete { background: #10b981; }
.milestone-status-dot.pending { background: #e5e7eb; border: 2px solid #9ca3af; box-sizing: border-box; }
.milestone-row-name {
    font-weight: 500;
    color: #293E4C;
    font-size: 0.95em;
}
.milestone-row-pct {
    font-size: 0.78em;
    color: #6c757d;
    margin-left: 4px;
}
.milestone-row-right {
    text-align: right;
}
.milestone-row-accrued {
    font-weight: 600;
    color: #293E4C;
    font-size: 0.95em;
}
.milestone-row-target {
    font-size: 0.78em;
    color: #6c757d;
    margin-top: 2px;
}
.module-breakdown-footer {
    border-top: 2px solid #e9ecef;
    margin-top: 12px;
    padding-top: 20px;
}
.module-breakdown-footer-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
}
.module-breakdown-footer-label {
    font-size: 0.88em;
    color: #6c757d;
}
.module-breakdown-footer-value {
    font-weight: 600;
    font-size: 0.95em;
    color: #293E4C;
}
</style>

<div id="moduleBreakdownModal" class="warehouse-selection-modal" style="display:none;">
    <div class="module-breakdown-modal-content">
        <div class="module-breakdown-header">
            <span class="module-breakdown-close" onclick="closeModuleBreakdownModal()">&times;</span>
            <h3>Accrued Module Cost</h3>
            <div class="module-breakdown-total">$<?php echo number_format($accrued_module_cost ?? 0, 2); ?></div>
            <?php if (($module_contract_value ?? 0) > 0): ?>
            <div class="module-breakdown-subtitle">of $<?php echo number_format($module_contract_value, 2); ?> contract value (<?php echo $milestone_completion['completion_percent'] ?? 0; ?>%)</div>
            <?php endif; ?>
        </div>
        <div class="module-breakdown-body">
            <?php if (!empty($module_milestone_rows)):
                foreach ($module_milestone_rows as $msr):
                    if ($msr['percentage'] <= 0) continue;
            ?>
            <div class="milestone-row">
                <div class="milestone-row-left">
                    <div class="milestone-status-dot <?php echo $msr['is_complete'] ? 'complete' : 'pending'; ?>"></div>
                    <div>
                        <span class="milestone-row-name"><?php echo htmlspecialchars($msr['name']); ?></span>
                        <span class="milestone-row-pct">(<?php echo $msr['percentage']; ?>%)</span>
                    </div>
                </div>
                <div class="milestone-row-right">
                    <div class="milestone-row-accrued">$<?php echo number_format($msr['accrued_amount'], 2); ?></div>
                    <div class="milestone-row-target">of $<?php echo number_format($msr['target_amount'], 2); ?></div>
                </div>
            </div>
            <?php endforeach; else: ?>
            <div style="text-align:center; padding:24px; color:#6c757d;">No milestone data configured</div>
            <?php endif; ?>

            <div class="module-breakdown-footer">
                <div class="module-breakdown-footer-row">
                    <span class="module-breakdown-footer-label">Accrued Module Cost</span>
                    <span class="module-breakdown-footer-value">$<?php echo number_format($accrued_module_cost ?? 0, 2); ?></span>
                </div>
                <div class="module-breakdown-footer-row">
                    <span class="module-breakdown-footer-label">Full Contract Value</span>
                    <span class="module-breakdown-footer-value">$<?php echo number_format($module_contract_value ?? 0, 2); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openModuleBreakdownModal() {
    document.getElementById('moduleBreakdownModal').style.display = 'block';
}
function closeModuleBreakdownModal() {
    document.getElementById('moduleBreakdownModal').style.display = 'none';
}
document.getElementById('moduleBreakdownModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeModuleBreakdownModal();
});
</script>

<!-- Forecasted vs Actual Cost Detail Modal -->
<style>
.cost-detail-modal-content {
    background: #fff;
    border-radius: 16px;
    width: 92%;
    max-width: 680px;
    margin: 5% auto;
    box-shadow: 0 24px 80px rgba(0,0,0,0.2), 0 8px 24px rgba(0,0,0,0.1);
    overflow: hidden;
    animation: modalSlideIn 0.3s ease;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}
.cost-detail-modal-header {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    padding: 28px 32px;
    position: relative;
    flex-shrink: 0;
}
.cost-detail-modal-header h3 {
    color: #fff;
    margin: 0 0 4px 0;
    font-size: 1.15em;
    font-weight: 600;
}
.cost-detail-modal-header .modal-date {
    color: rgba(255,255,255,0.8);
    font-size: 0.9em;
}
.cost-detail-modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    opacity: 0.7;
    transition: all 0.2s;
    border-radius: 50%;
    background: transparent;
}
.cost-detail-modal-close:hover {
    opacity: 1;
    background: rgba(255,255,255,0.15);
}
.cost-detail-modal-body {
    padding: 28px 32px;
    overflow-y: auto;
    flex: 1;
}
.cost-detail-summary {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.cost-detail-card {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
}
.cost-detail-card.forecasted {
    border-left: 4px solid #488C9A;
}
.cost-detail-card.actual {
    border-left: 4px solid #293E4C;
}
.cost-detail-card.variance {
    grid-column: 1 / -1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
}
.cost-detail-card.variance.positive {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    border-left: 4px solid #dc3545;
}
.cost-detail-card.variance.negative {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-left: 4px solid #28a745;
}
.cost-detail-card-label {
    font-size: 0.75em;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.cost-detail-card-value {
    font-size: 1.8em;
    font-weight: 700;
    color: #293E4C;
}
.cost-detail-card.forecasted .cost-detail-card-value {
    color: #488C9A;
}
.cost-detail-card.actual .cost-detail-card-value {
    color: #293E4C;
}
.variance-label {
    font-size: 0.85em;
    color: #6c757d;
}
.variance-value {
    font-size: 1.2em;
    font-weight: 700;
}
.variance.positive .variance-value {
    color: #dc3545;
}
.variance.negative .variance-value {
    color: #28a745;
}
.cost-detail-breakdown {
    margin-top: 24px;
}
.cost-detail-breakdown h4 {
    font-size: 0.9em;
    color: #293E4C;
    margin: 0 0 16px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid #e9ecef;
}
.cost-detail-breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f3f4;
}
.cost-detail-breakdown-item:last-child {
    border-bottom: none;
}
.breakdown-label {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #495057;
    font-size: 0.9em;
}
.breakdown-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}
.breakdown-dot.forecasted { background: #488C9A; }
.breakdown-dot.actual { background: #293E4C; }
.breakdown-value {
    font-weight: 600;
    color: #293E4C;
}
</style>

<div id="costDetailModal" class="warehouse-selection-modal" style="display:none;">
    <div class="cost-detail-modal-content">
        <div class="cost-detail-modal-header">
            <span class="cost-detail-modal-close" onclick="closeCostDetailModal()">&times;</span>
            <h3>Cost Comparison</h3>
            <div class="modal-date" id="costDetailDate"></div>
        </div>
        <div class="cost-detail-modal-body">
            <div class="cost-detail-summary">
                <div class="cost-detail-card forecasted">
                    <div class="cost-detail-card-label">Forecasted Cost</div>
                    <div class="cost-detail-card-value" id="costDetailForecasted">$0.00</div>
                </div>
                <div class="cost-detail-card actual">
                    <div class="cost-detail-card-label">Actual Cost</div>
                    <div class="cost-detail-card-value" id="costDetailActual">$0.00</div>
                </div>
                <div class="cost-detail-card variance" id="costDetailVarianceCard">
                    <div class="variance-label">Variance from Forecast</div>
                    <div class="variance-value" id="costDetailVariance">$0.00 (0%)</div>
                </div>
            </div>

            <div class="cost-detail-breakdown">
                <h4>Cumulative Cost at This Point</h4>
                <div class="cost-detail-breakdown-item clickable-breakdown" onclick="toggleBreakdown('forecasted')">
                    <span class="breakdown-label">
                        <span class="breakdown-dot forecasted"></span>
                        Forecasted (Cumulative)
                        <span class="breakdown-expand-icon" id="forecastedExpandIcon">▶</span>
                    </span>
                    <span class="breakdown-value" id="costDetailForecastedCumulative">$0.00</span>
                </div>
                <div class="breakdown-detail-panel" id="forecastedBreakdownPanel" style="display: none;">
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Module Costs (Milestones)</span>
                        <span class="breakdown-detail-value" id="forecastedMilestones">$0.00</span>
                    </div>
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Freight & Accessorial</span>
                        <span class="breakdown-detail-value" id="forecastedFreight">$0.00</span>
                    </div>
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Warehousing</span>
                        <span class="breakdown-detail-value" id="forecastedWarehousing">$0.00</span>
                    </div>
                </div>
                <div class="cost-detail-breakdown-item clickable-breakdown" onclick="toggleBreakdown('actual')">
                    <span class="breakdown-label">
                        <span class="breakdown-dot actual"></span>
                        Actual (Cumulative)
                        <span class="breakdown-expand-icon" id="actualExpandIcon">▶</span>
                    </span>
                    <span class="breakdown-value" id="costDetailActualCumulative">$0.00</span>
                </div>
                <div class="breakdown-detail-panel" id="actualBreakdownPanel" style="display: none;">
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Module Costs (Milestones)</span>
                        <span class="breakdown-detail-value" id="actualMilestones">$0.00</span>
                    </div>
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Freight & Accessorial</span>
                        <span class="breakdown-detail-value" id="actualFreight">$0.00</span>
                    </div>
                    <div class="breakdown-detail-item">
                        <span class="breakdown-detail-label">Warehousing</span>
                        <span class="breakdown-detail-value" id="actualWarehousing">$0.00</span>
                    </div>
                </div>
            </div>

            <p style="margin-top: 24px; padding: 16px; background: #f8f9fa; border-radius: 8px; font-size: 0.85em; color: #6c757d;">
                <strong>Note:</strong> Forecasted costs are based on the primary logistics plan projections.
                Actual costs are calculated from completed deliveries and triggered milestones.
                <br><br><em>Click on Forecasted or Actual cumulative values to see breakdown.</em>
            </p>
        </div>
    </div>
</div>

<style>
.clickable-breakdown {
    cursor: pointer;
    transition: background 0.2s;
    border-radius: 6px;
    margin: 0 -8px;
    padding-left: 8px !important;
    padding-right: 8px !important;
}
.clickable-breakdown:hover {
    background: rgba(72, 140, 154, 0.08);
}
.breakdown-expand-icon {
    font-size: 0.7em;
    margin-left: 6px;
    color: #6c757d;
    transition: transform 0.2s;
    display: inline-block;
}
.breakdown-expand-icon.expanded {
    transform: rotate(90deg);
}
.breakdown-detail-panel {
    background: linear-gradient(135deg, #f8f9fa 0%, #f0f4f5 100%);
    border-radius: 8px;
    padding: 12px 16px;
    margin: 8px 0 12px 0;
    border-left: 3px solid #488C9A;
}
.breakdown-detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}
.breakdown-detail-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.breakdown-detail-item:first-child {
    padding-top: 0;
}
.breakdown-detail-label {
    font-size: 0.85em;
    color: #495057;
}
.breakdown-detail-value {
    font-weight: 600;
    font-size: 0.9em;
    color: #293E4C;
}
</style>

<script>
function toggleBreakdown(type) {
    const panel = document.getElementById(type + 'BreakdownPanel');
    const icon = document.getElementById(type + 'ExpandIcon');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        icon.classList.add('expanded');
    } else {
        panel.style.display = 'none';
        icon.classList.remove('expanded');
    }
}

function openCostDetailModal(date, forecasted, actual, forecastedCumulative, actualCumulative, forecastBreakdown, actualBreakdown) {
    // Format date
    const dateObj = new Date(date);
    const formattedDate = dateObj.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    });
    document.getElementById('costDetailDate').textContent = formattedDate;

    // Format currency values
    const formatCurrency = (val) => '$' + Number(val || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    document.getElementById('costDetailForecasted').textContent = formatCurrency(forecasted);
    document.getElementById('costDetailActual').textContent = formatCurrency(actual);
    document.getElementById('costDetailForecastedCumulative').textContent = formatCurrency(forecastedCumulative);
    document.getElementById('costDetailActualCumulative').textContent = formatCurrency(actualCumulative);

    // Populate forecasted breakdown
    if (forecastBreakdown) {
        document.getElementById('forecastedMilestones').textContent = formatCurrency(forecastBreakdown.milestones || 0);
        document.getElementById('forecastedFreight').textContent = formatCurrency(forecastBreakdown.freight || 0);
        document.getElementById('forecastedWarehousing').textContent = formatCurrency(forecastBreakdown.warehousing || 0);
    } else {
        document.getElementById('forecastedMilestones').textContent = formatCurrency(0);
        document.getElementById('forecastedFreight').textContent = formatCurrency(0);
        document.getElementById('forecastedWarehousing').textContent = formatCurrency(0);
    }

    // Populate actual breakdown
    if (actualBreakdown) {
        document.getElementById('actualMilestones').textContent = formatCurrency(actualBreakdown.milestones || 0);
        document.getElementById('actualFreight').textContent = formatCurrency(actualBreakdown.freight || 0);
        document.getElementById('actualWarehousing').textContent = formatCurrency(actualBreakdown.warehousing || 0);
    } else {
        document.getElementById('actualMilestones').textContent = formatCurrency(0);
        document.getElementById('actualFreight').textContent = formatCurrency(0);
        document.getElementById('actualWarehousing').textContent = formatCurrency(0);
    }

    // Reset breakdown panels to collapsed state
    document.getElementById('forecastedBreakdownPanel').style.display = 'none';
    document.getElementById('actualBreakdownPanel').style.display = 'none';
    document.getElementById('forecastedExpandIcon').classList.remove('expanded');
    document.getElementById('actualExpandIcon').classList.remove('expanded');

    // Calculate and display variance
    const variance = (actual || 0) - (forecasted || 0);
    const variancePercent = forecasted > 0 ? (variance / forecasted * 100) : 0;
    const varianceCard = document.getElementById('costDetailVarianceCard');
    const varianceValue = document.getElementById('costDetailVariance');

    if (variance > 0) {
        varianceCard.classList.remove('negative');
        varianceCard.classList.add('positive');
        varianceValue.textContent = '+' + formatCurrency(variance) + ' (+' + variancePercent.toFixed(1) + '%)';
    } else if (variance < 0) {
        varianceCard.classList.remove('positive');
        varianceCard.classList.add('negative');
        varianceValue.textContent = formatCurrency(variance) + ' (' + variancePercent.toFixed(1) + '%)';
    } else {
        varianceCard.classList.remove('positive', 'negative');
        varianceValue.textContent = '$0.00 (0%)';
    }

    document.getElementById('costDetailModal').style.display = 'block';
}

function closeCostDetailModal() {
    document.getElementById('costDetailModal').style.display = 'none';
}

document.getElementById('costDetailModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCostDetailModal();
});
</script>
