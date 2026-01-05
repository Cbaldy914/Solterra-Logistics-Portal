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
