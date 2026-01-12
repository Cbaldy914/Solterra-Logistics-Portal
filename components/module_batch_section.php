<?php
// Shared Module Batch Section (UI + JS) used by add_module_batch.php and edit_module_batch.php
// Expected variables in scope:
// - $manufacturers: array of ['id'=>int,'name'=>string]
// - $prefManufacturerId: int|null (optional preselect)
// - $prefLocationId: int|null (optional preselect)
// - $existingWattages: array of ['wattage'=>int,'quantity'=>int] (optional for edit)
// - $module (optional): existing module data for edit mode
?>

<style>
    .module-section { grid-column: 1 / -1; margin-top: 0; padding-top: 0; }
    .form-grid { display: grid; grid-template-columns: 1fr; gap: 24px; }
    .form-section { padding: 0; border: none; background: transparent; }
    .form-section h2 { font-size: 1.2rem; font-weight: 600; color: #1a1a1a; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #488C9A; }
    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-weight: 500; color: #333; margin-bottom: 8px; font-size: 0.95rem; }
    .input-group input, .input-group select, .input-group textarea { width: 100%; padding: 12px 16px; border: 2px solid #e8e8e8; border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; box-sizing: border-box; background: #fafafa; }
    .input-group input:focus, .input-group select:focus, .input-group textarea:focus { outline: none; border-color: #488C9A; background: #fff; box-shadow: 0 0 0 3px rgba(72,140,154,0.1); }

    /* Two-column layout for Manufacturer/Location and Wattage/Quantities */
    .mb-two-column-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 32px;
        align-items: start;
    }
    .mb-left-column, .mb-right-column {
        min-width: 0;
    }
    .mb-right-column .input-group label {
        margin-bottom: 12px;
    }

    .wattage-container { margin: 0; }
    .wattage-entry { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; background: #f8f9fa; border-radius: 10px; margin-bottom: 10px; border: 1px solid #e9ecef; }
    .wattage-entry .input-group { margin-bottom: 0; }
    .wattage-entry .input-group label { font-size: 0.85rem; margin-bottom: 4px; }
    .wattage-entry .input-group input { padding: 10px 12px; font-size: 0.95rem; }
    .remove-btn { background: #dc3545; color: #fff; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.85rem; }
    .add-wattage-btn { background: #488C9A; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 0.9rem; margin-top: 8px; }

    @media (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr; }
        .mb-two-column-layout { grid-template-columns: 1fr; gap: 24px; }
        .wattage-entry { grid-template-columns: 1fr; gap: 10px; }
    }

    /* Inline Buttons Row - matching upload_pallets.php style */
    .inline-buttons-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0;
        margin-bottom: 24px;
        margin-top: 24px;
    }
    .logistics-inline-btn,
    .module-docs-inline-btn {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border: 2px solid #488C9A;
        border-radius: 8px;
        padding: 12px 20px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #488C9A;
        transition: all 0.2s ease;
        margin-bottom: 0;
    }
    .module-docs-inline-btn {
        border-color: #6f42c1;
        color: #6f42c1;
        margin-left: 12px;
    }
    .logistics-inline-btn:hover {
        background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        color: #fff;
    }
    .module-docs-inline-btn:hover {
        background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
        color: #fff;
    }
    .logistics-inline-btn.has-data {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        border-color: #28a745;
        color: #155724;
    }
    .logistics-inline-btn.has-data:hover {
        background: linear-gradient(135deg, #28a745 0%, #218838 100%);
        color: #fff;
    }
    .module-docs-inline-btn.has-data {
        background: linear-gradient(135deg, #e2d9f3 0%, #d4c7eb 100%);
        border-color: #6f42c1;
        color: #6f42c1;
    }
    .module-docs-inline-btn.has-data:hover {
        background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
        color: #fff;
    }
    .logistics-inline-btn .badge,
    .module-docs-inline-btn .badge {
        background: #28a745;
        color: white;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 4px;
    }
    .module-docs-inline-btn .badge {
        background: #6f42c1;
    }

    /* Logistics Panel Overlay and Slide-out */
    .logistics-panel-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 1001;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    .logistics-panel-overlay.open {
        opacity: 1;
        visibility: visible;
    }
    .logistics-panel {
        position: fixed;
        top: 0;
        right: -450px;
        width: 450px;
        max-width: 90vw;
        height: 100vh;
        background: #fff;
        box-shadow: -5px 0 25px rgba(0, 0, 0, 0.15);
        z-index: 1002;
        transition: right 0.3s ease;
        display: flex;
        flex-direction: column;
    }
    .logistics-panel.open {
        right: 0;
    }
    .logistics-panel-header {
        padding: 20px 24px;
        background: #ffffff;
        color: #293E4C;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #e9ecef;
    }
    .logistics-panel-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #293E4C;
    }
    .logistics-panel-close {
        background: none;
        border: none;
        color: #6c757d;
        font-size: 24px;
        cursor: pointer;
        padding: 0;
        line-height: 1;
        opacity: 0.8;
    }
    .logistics-panel-close:hover {
        opacity: 1;
    }
    .logistics-panel-body {
        flex: 1;
        overflow-y: auto;
        padding: 24px;
    }
    .logistics-panel-section {
        margin-bottom: 24px;
    }
    .logistics-panel-section h5 {
        margin: 0 0 12px 0;
        color: #293E4C;
        font-size: 14px;
        font-weight: 600;
        border-bottom: 1px solid #e9ecef;
        padding-bottom: 8px;
    }
    .logistics-panel-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .logistics-panel-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .logistics-panel-field label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .logistics-panel-field input,
    .logistics-panel-field textarea {
        padding: 8px 10px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        font-size: 13px;
        transition: border-color 0.2s;
    }
    .logistics-panel-field input:focus,
    .logistics-panel-field textarea:focus {
        outline: none;
        border-color: #488C9A;
    }
    .logistics-panel-field input[readonly] {
        background: #f8f9fa;
        color: #6c757d;
    }
    .logistics-panel-field.full-width {
        grid-column: 1 / -1;
    }

    /* Location Address Display */
    .location-address-display {
        margin-top: 8px;
        padding: 10px 14px;
        background: linear-gradient(135deg, #f0f8ff 0%, #e7f3ff 100%);
        border: 1px solid #b8daff;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #0056b3;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .location-address-display .address-icon {
        font-size: 1rem;
    }
</style>

<div class="module-section">

    <div class="form-grid">
        <div class="form-section">
            <h2>Manufacturer & Modules</h2>

            <div class="mb-two-column-layout">
                <!-- Left Column: Manufacturer & Location -->
                <div class="mb-left-column">
                    <div class="input-group">
                        <label for="manufacturer_id">Manufacturer</label>
                        <select name="manufacturer_id" id="manufacturer_id" onchange="mb_handleManufacturerChange(this)">
                            <option value="">Select Manufacturer</option>
                            <?php foreach (($manufacturers ?? []) as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo (!empty($prefManufacturerId) && (int)$prefManufacturerId === (int)$m['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="add_new" style="background-color: #f0f8ff; font-style: italic;">+ Add New Manufacturer</option>
                        </select>
                    </div>

                    <div class="input-group" style="margin-bottom: 0;">
                        <label for="location_id">Location</label>
                        <select name="location_id" id="location_id" <?php echo empty($prefManufacturerId) ? 'disabled' : ''; ?>>
                            <option value="">Select a manufacturer first</option>
                        </select>
                        <div id="locationAddressDisplay" class="location-address-display" style="display: none;">
                            <span class="address-icon">📍</span>
                            <span id="locationAddressText"></span>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Wattage & Quantities -->
                <div class="mb-right-column">
                    <div class="input-group" style="margin-bottom: 0;">
                        <label>Wattage & Quantities</label>
                        <div id="wattage-container" class="wattage-container"></div>
                        <button type="button" class="add-wattage-btn" onclick="mb_addWattageField()">+ Add Wattage</button>
                    </div>
                </div>
            </div>

            <!-- Cost Per Watt (Optional) -->
            <div class="input-group" style="margin-top: 20px; max-width: 300px;">
                <label for="cost_per_watt">Cost Per Watt ($/W) <span style="color: #6c757d; font-weight: 400; font-size: 0.85em;">(optional)</span></label>
                <input type="number" step="0.000001" min="0" name="cost_per_watt" id="cost_per_watt" placeholder="e.g. 0.25" value="<?php echo htmlspecialchars($module['cost_per_watt'] ?? ''); ?>">
                <small style="color: #6c757d; font-size: 0.8rem; margin-top: 4px; display: block;">Enter the module cost in price per watt for reporting.</small>
            </div>
        </div>
    </div>

    <!-- Logistics & Documentation Buttons - matching upload_pallets.php style -->
    <div class="inline-buttons-row">
        <button type="button" class="logistics-inline-btn" id="logisticsBtnManual" onclick="mb_openLogisticsPanel()">
            <span>📦</span>
            <span>Logistics Specifications</span>
            <span class="badge" id="logisticsBadgeManual" style="display: none;">Set</span>
        </button>
        <button type="button" class="module-docs-inline-btn" id="moduleDocsBtnManual" onclick="mb_openModuleDocsPanel()">
            <span>📄</span>
            <span>Module Documentation</span>
            <span class="badge" id="moduleDocsBadgeManual" style="display: none;"></span>
        </button>
    </div>
</div>

<!-- Logistics Panel Overlay -->
<div class="logistics-panel-overlay" id="mb_logisticsPanelOverlay"></div>

<!-- Logistics Slide-out Panel -->
<div class="logistics-panel" id="mb_logisticsPanel">
    <div class="logistics-panel-header" style="border-bottom: 2px solid #488C9A;">
        <h3 style="color: #488C9A;"><span style="margin-right: 8px;">📦</span>Logistics Specifications</h3>
        <button type="button" class="logistics-panel-close" id="mb_logisticsPanelClose">&times;</button>
    </div>
    <div class="logistics-panel-body">
        <p style="margin: 0 0 20px 0; color: #6c757d; font-size: 13px;">
            Optional specifications for pallet dimensions, truck loading, and handling requirements.
        </p>

        <!-- Truck & Pallet Info -->
        <div class="logistics-panel-section">
            <h5>Truck & Pallet Info</h5>
            <div class="logistics-panel-grid">
                <div class="logistics-panel-field">
                    <label>Modules per Pallet</label>
                    <input type="number" id="modules_per_pallet" name="modules_per_pallet" min="1" placeholder="e.g. 30" value="<?php echo htmlspecialchars($module['modules_per_pallet'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Pallets per Truck</label>
                    <input type="number" id="pallets_per_truck" name="pallets_per_truck" min="1" placeholder="e.g. 22" value="<?php echo htmlspecialchars($module['pallets_per_truck'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Modules per Truck</label>
                    <input type="number" id="modules_per_truck" name="modules_per_truck" min="1" placeholder="Auto-calculated" readonly style="background-color: #f8f9fa; color: #6c757d;" value="<?php echo htmlspecialchars($module['modules_per_truck'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Pallet Dimensions -->
        <div class="logistics-panel-section">
            <h5>Pallet Dimensions</h5>
            <div class="logistics-panel-grid">
                <div class="logistics-panel-field">
                    <label>Length (mm)</label>
                    <input type="number" id="pallet_length_mm" name="pallet_length_mm" min="1" placeholder="e.g. 2384" value="<?php echo htmlspecialchars($module['pallet_length_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Depth (mm)</label>
                    <input type="number" id="pallet_depth_mm" name="pallet_depth_mm" min="1" placeholder="e.g. 1303" value="<?php echo htmlspecialchars($module['pallet_depth_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Stack Height (mm)</label>
                    <input type="number" id="pallet_double_stacked_height_mm" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g. 2200" value="<?php echo htmlspecialchars($module['pallet_double_stacked_height_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Weight (kg)</label>
                    <input type="number" id="pallet_total_weight_kg" name="pallet_total_weight_kg" min="1" placeholder="e.g. 1200" value="<?php echo htmlspecialchars($module['pallet_total_weight_kg'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Handling Requirements -->
        <div class="logistics-panel-section">
            <h5>Handling Requirements</h5>
            <div class="logistics-panel-grid">
                <div class="logistics-panel-field">
                    <label>Forklift Long Side (mm)</label>
                    <input type="number" id="forklift_truck_long_side_mm" name="forklift_truck_long_side_mm" min="1" placeholder="e.g. 1200" value="<?php echo htmlspecialchars($module['forklift_truck_long_side_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Forklift Short Side (mm)</label>
                    <input type="number" id="forklift_truck_short_side_mm" name="forklift_truck_short_side_mm" min="1" placeholder="e.g. 1000" value="<?php echo htmlspecialchars($module['forklift_truck_short_side_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Pallet Jack Long (mm)</label>
                    <input type="number" id="pallet_jack_long_side_mm" name="pallet_jack_long_side_mm" min="1" placeholder="e.g. 1150" value="<?php echo htmlspecialchars($module['pallet_jack_long_side_mm'] ?? ''); ?>">
                </div>
                <div class="logistics-panel-field">
                    <label>Pallet Jack Short (mm)</label>
                    <input type="number" id="pallet_jack_short_side_mm" name="pallet_jack_short_side_mm" min="1" placeholder="e.g. 800" value="<?php echo htmlspecialchars($module['pallet_jack_short_side_mm'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Stacking Instructions -->
        <div class="logistics-panel-section">
            <h5>Stacking Instructions</h5>
            <div class="logistics-panel-field full-width" style="margin-bottom: 12px;">
                <label>Warehouse Stacking</label>
                <textarea id="stacking_in_warehouse" name="stacking_in_warehouse" rows="2" placeholder="Instructions for warehouse stacking..."><?php echo htmlspecialchars($module['stacking_in_warehouse'] ?? ''); ?></textarea>
            </div>
            <div class="logistics-panel-field full-width" style="margin-bottom: 12px;">
                <label>Transport Stacking</label>
                <textarea id="stacking_during_transport" name="stacking_during_transport" rows="2" placeholder="Instructions for transport stacking..."><?php echo htmlspecialchars($module['stacking_during_transport'] ?? ''); ?></textarea>
            </div>
            <div class="logistics-panel-field full-width">
                <label>Module Notes</label>
                <textarea id="module_notes" name="module_notes" rows="2" placeholder="General notes about the modules..."><?php echo htmlspecialchars($module['module_notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e9ecef;">
            <button type="button" id="mb_logisticsClearBtn" style="flex: 1; padding: 12px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; font-size: 14px; color: #6c757d;">
                Clear All
            </button>
            <button type="button" id="mb_logisticsDoneBtn" style="flex: 2; padding: 12px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); border: none; border-radius: 8px; cursor: pointer; font-size: 14px; color: white; font-weight: 600;">
                Done
            </button>
        </div>
    </div>
</div>

<!-- Module Documentation Panel Overlay -->
<div class="logistics-panel-overlay" id="mb_moduleDocsPanelOverlay"></div>

<!-- Module Documentation Slide-out Panel -->
<div class="logistics-panel" id="mb_moduleDocsPanel" style="border-left: 4px solid #6f42c1;">
    <div class="logistics-panel-header" style="border-bottom: 2px solid #6f42c1;">
        <h3 style="color: #6f42c1;"><span style="margin-right: 8px;">📄</span>Module Documentation</h3>
        <button type="button" class="logistics-panel-close" id="mb_moduleDocsPanelClose">&times;</button>
    </div>
    <div class="logistics-panel-body">
        <p style="margin: 0 0 20px 0; color: #6c757d; font-size: 13px;">
            Attach documentation files (spec sheets, flash test data, invoices) to this module batch.
            Files will be saved to the project's document library.
        </p>

        <!-- Document Type Selection -->
        <div class="logistics-panel-section">
            <h5>Document Details</h5>
            <div class="logistics-panel-field full-width" style="margin-bottom: 16px;">
                <label>Document Sub-Type</label>
                <select id="module_docs_sub_type" name="module_docs_sub_type" style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 6px; font-size: 13px;">
                    <option value="">Choose sub-type...</option>
                    <option value="Module Invoice">Module Invoice</option>
                    <option value="Flash Test Data">Flash Test Data</option>
                    <option value="Spec Sheets">Spec Sheets</option>
                </select>
            </div>
            <div class="logistics-panel-field full-width" style="margin-bottom: 16px;">
                <label>Description (Optional)</label>
                <input type="text" id="module_docs_description" name="module_docs_description" placeholder="Brief description of documents" style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
            </div>
        </div>

        <!-- File Upload Area -->
        <div class="logistics-panel-section">
            <h5>Select Files</h5>
            <div id="mb_moduleDocsDropArea" style="border: 2px dashed #6f42c1; border-radius: 12px; padding: 30px 20px; text-align: center; cursor: pointer; background: linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%); transition: all 0.3s ease;">
                <div style="font-size: 36px; color: #6f42c1; margin-bottom: 12px;">📎</div>
                <div style="font-size: 0.95rem; font-weight: 600; color: #293E4C; margin-bottom: 6px;">Drop files here or click to browse</div>
                <div style="font-size: 0.8rem; color: #6c757d;">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV</div>
            </div>
            <input type="file" id="module_docs" name="module_docs[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv">

            <!-- Selected Files List -->
            <div id="mb_moduleDocsFileList" style="margin-top: 16px;"></div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #e9ecef;">
            <button type="button" id="mb_moduleDocsClearBtn" style="flex: 1; padding: 12px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; font-size: 14px; color: #6c757d;">
                Clear All
            </button>
            <button type="button" id="mb_moduleDocsDoneBtn" style="flex: 2; padding: 12px; background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); border: none; border-radius: 8px; cursor: pointer; font-size: 14px; color: white; font-weight: 600;">
                Done
            </button>
        </div>
    </div>
</div>

<script>
// Store location data for address display
let mb_locationDataCache = {};

function mb_addWattageField(wattage = '', quantity = '') {
    const container = document.getElementById('wattage-container');
    const index = container.children.length;
    const div = document.createElement('div');
    div.className = 'wattage-entry';

    const wGroup = document.createElement('div');
    wGroup.className = 'input-group';
    const wLabel = document.createElement('label');
    wLabel.textContent = 'Wattage (W)';
    const wInput = document.createElement('input');
    wInput.type = 'number'; wInput.step = '1'; wInput.name = 'wattages[]'; wInput.required = true; wInput.placeholder = 'e.g. 555';
    if (wattage !== '') wInput.value = wattage;
    wGroup.appendChild(wLabel); wGroup.appendChild(wInput);

    const qGroup = document.createElement('div');
    qGroup.className = 'input-group';
    const qLabel = document.createElement('label');
    qLabel.textContent = 'Quantity';
    const qInput = document.createElement('input');
    qInput.type = 'number'; qInput.step = '1'; qInput.name = 'quantities[]'; qInput.required = true; qInput.placeholder = 'e.g. 1000';
    if (quantity !== '') qInput.value = quantity;
    qGroup.appendChild(qLabel); qGroup.appendChild(qInput);

    const removeButton = document.createElement('button');
    removeButton.type = 'button'; removeButton.textContent = 'Remove'; removeButton.className = 'remove-btn';
    removeButton.onclick = () => container.removeChild(div);

    div.appendChild(wGroup); div.appendChild(qGroup); div.appendChild(removeButton);
    container.appendChild(div);
}

function mb_handleManufacturerChange(select) {
    const locationSelect = document.getElementById('location_id');
    const addressDisplay = document.getElementById('locationAddressDisplay');
    const addressText = document.getElementById('locationAddressText');

    // Reset address display
    if (addressDisplay) {
        addressDisplay.style.display = 'none';
        addressText.textContent = '';
    }
    mb_locationDataCache = {};

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
            if (data && Array.isArray(data.locations)) {
                const def = document.createElement('option');
                def.value = ''; def.textContent = 'Select a location';
                locationSelect.appendChild(def);
                data.locations.forEach(loc => {
                    // Store location data for address display
                    mb_locationDataCache[loc.id] = loc.formatted_address || loc.city || '';
                    const opt = document.createElement('option');
                    opt.value = loc.id;
                    opt.textContent = (loc.location_name ? (loc.location_name + ' — ') : '') + loc.formatted_address;
                    locationSelect.appendChild(opt);
                });
            } else {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = 'No active locations';
                locationSelect.appendChild(opt);
            }
            locationSelect.disabled = false;
            // Preselect if provided from server
            const pref = '<?php echo isset($prefLocationId) ? (int)$prefLocationId : 0; ?>';
            if (pref && locationSelect.querySelector('option[value="'+pref+'"]')) {
                locationSelect.value = pref;
                // Show address display for preselected location
                if (mb_locationDataCache[pref] && addressDisplay) {
                    addressText.textContent = mb_locationDataCache[pref];
                    addressDisplay.style.display = 'flex';
                }
            }
        })
        .catch(() => {
            locationSelect.innerHTML = '<option value="">Error loading locations</option>';
            locationSelect.disabled = false;
        });
}

function mb_calcModulesPerTruck() {
    const mpp = parseInt(document.getElementById('modules_per_pallet').value, 10);
    const ppt = parseInt(document.getElementById('pallets_per_truck').value, 10);
    const out = document.getElementById('modules_per_truck');
    if (!isNaN(mpp) && !isNaN(ppt) && mpp > 0 && ppt > 0) out.value = (mpp * ppt);
}

// ========== Logistics Panel Functions ==========
function mb_openLogisticsPanel() {
    document.getElementById('mb_logisticsPanel').classList.add('open');
    document.getElementById('mb_logisticsPanelOverlay').classList.add('open');
}

function mb_closeLogisticsPanel() {
    document.getElementById('mb_logisticsPanel').classList.remove('open');
    document.getElementById('mb_logisticsPanelOverlay').classList.remove('open');
    mb_updateLogisticsButtonStatus();
}

function mb_updateLogisticsButtonStatus() {
    const logisticsFields = [
        'modules_per_pallet', 'pallets_per_truck', 'pallet_length_mm', 'pallet_depth_mm',
        'pallet_double_stacked_height_mm', 'pallet_total_weight_kg', 'forklift_truck_long_side_mm',
        'forklift_truck_short_side_mm', 'pallet_jack_long_side_mm', 'pallet_jack_short_side_mm',
        'stacking_in_warehouse', 'stacking_during_transport', 'module_notes'
    ];

    let hasData = false;
    logisticsFields.forEach(field => {
        const el = document.getElementById(field);
        if (el && el.value && el.value.trim() !== '') {
            hasData = true;
        }
    });

    const btn = document.getElementById('logisticsBtnManual');
    const badge = document.getElementById('logisticsBadgeManual');

    if (btn) {
        if (hasData) {
            btn.classList.add('has-data');
            if (badge) {
                badge.style.display = 'inline';
            }
        } else {
            btn.classList.remove('has-data');
            if (badge) {
                badge.style.display = 'none';
            }
        }
    }
}

// ========== Module Documentation Panel Functions ==========
let mb_moduleDocsSelectedFiles = [];

function mb_openModuleDocsPanel() {
    document.getElementById('mb_moduleDocsPanel').classList.add('open');
    document.getElementById('mb_moduleDocsPanelOverlay').classList.add('open');
    mb_updateModuleDocsFileList();
}

function mb_closeModuleDocsPanel() {
    document.getElementById('mb_moduleDocsPanel').classList.remove('open');
    document.getElementById('mb_moduleDocsPanelOverlay').classList.remove('open');
    mb_updateModuleDocsButtonStatus();
}

function mb_handleModuleDocsFiles(files) {
    const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'csv'];

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const ext = file.name.split('.').pop().toLowerCase();

        if (!allowedExtensions.includes(ext)) {
            alert('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions.join(', '));
            continue;
        }

        // Check if file already added
        const exists = mb_moduleDocsSelectedFiles.some(f => f.name === file.name && f.size === file.size);
        if (!exists) {
            mb_moduleDocsSelectedFiles.push(file);
        }
    }

    mb_updateModuleDocsFileList();
    mb_syncFilesToInput();
}

function mb_updateModuleDocsFileList() {
    const fileList = document.getElementById('mb_moduleDocsFileList');
    if (!fileList) return;

    if (mb_moduleDocsSelectedFiles.length === 0) {
        fileList.innerHTML = '';
        return;
    }

    let html = '';
    mb_moduleDocsSelectedFiles.forEach((file, index) => {
        const sizeKB = (file.size / 1024).toFixed(1);
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        const sizeStr = file.size > 1024 * 1024 ? sizeMB + ' MB' : sizeKB + ' KB';

        html += `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f8f9fa; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e9ecef;">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                    <span style="font-size: 16px;">📄</span>
                    <div style="overflow: hidden;">
                        <div style="font-size: 0.9rem; color: #293E4C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${file.name}</div>
                        <div style="font-size: 0.75rem; color: #6c757d;">${sizeStr}</div>
                    </div>
                </div>
                <button type="button" onclick="mb_removeModuleDocsFile(${index})" style="background: none; border: none; color: #dc3545; cursor: pointer; font-size: 18px; padding: 0 4px;">&times;</button>
            </div>
        `;
    });

    fileList.innerHTML = html;
}

function mb_removeModuleDocsFile(index) {
    mb_moduleDocsSelectedFiles.splice(index, 1);
    mb_updateModuleDocsFileList();
    mb_syncFilesToInput();
    mb_updateModuleDocsButtonStatus();
}

function mb_syncFilesToInput() {
    // Create a new DataTransfer to set files on the input
    const dt = new DataTransfer();
    mb_moduleDocsSelectedFiles.forEach(file => {
        dt.items.add(file);
    });
    const fileInput = document.getElementById('module_docs');
    if (fileInput) {
        fileInput.files = dt.files;
    }
}

function mb_updateModuleDocsButtonStatus() {
    const hasFiles = mb_moduleDocsSelectedFiles.length > 0;
    const fileCount = mb_moduleDocsSelectedFiles.length;

    const btn = document.getElementById('moduleDocsBtnManual');
    const badge = document.getElementById('moduleDocsBadgeManual');

    if (btn) {
        if (hasFiles) {
            btn.classList.add('has-data');
            if (badge) {
                badge.textContent = fileCount + ' file' + (fileCount > 1 ? 's' : '');
                badge.style.display = 'inline';
            }
        } else {
            btn.classList.remove('has-data');
            if (badge) {
                badge.style.display = 'none';
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const existing = <?php echo json_encode($existingWattages ?? []); ?>;
    if (existing.length) {
        existing.forEach(row => mb_addWattageField(row.wattage, row.quantity));
    } else {
        mb_addWattageField();
    }
    const mSel = document.getElementById('manufacturer_id');
    if (mSel && mSel.value) { mb_handleManufacturerChange(mSel); }
    const mpp = document.getElementById('modules_per_pallet');
    const ppt = document.getElementById('pallets_per_truck');
    if (mpp) mpp.addEventListener('input', mb_calcModulesPerTruck);
    if (ppt) ppt.addEventListener('input', mb_calcModulesPerTruck);

    // Logistics Panel Event Listeners
    const logisticsCloseBtn = document.getElementById('mb_logisticsPanelClose');
    const logisticsOverlay = document.getElementById('mb_logisticsPanelOverlay');
    const logisticsDoneBtn = document.getElementById('mb_logisticsDoneBtn');
    const logisticsClearBtn = document.getElementById('mb_logisticsClearBtn');

    if (logisticsCloseBtn) logisticsCloseBtn.addEventListener('click', mb_closeLogisticsPanel);
    if (logisticsOverlay) logisticsOverlay.addEventListener('click', mb_closeLogisticsPanel);
    if (logisticsDoneBtn) logisticsDoneBtn.addEventListener('click', mb_closeLogisticsPanel);

    if (logisticsClearBtn) {
        logisticsClearBtn.addEventListener('click', function() {
            const logisticsFields = [
                'modules_per_pallet', 'pallets_per_truck', 'modules_per_truck', 'pallet_length_mm', 'pallet_depth_mm',
                'pallet_double_stacked_height_mm', 'pallet_total_weight_kg', 'forklift_truck_long_side_mm',
                'forklift_truck_short_side_mm', 'pallet_jack_long_side_mm', 'pallet_jack_short_side_mm',
                'stacking_in_warehouse', 'stacking_during_transport', 'module_notes'
            ];
            logisticsFields.forEach(field => {
                const el = document.getElementById(field);
                if (el) el.value = '';
            });
            mb_updateLogisticsButtonStatus();
        });
    }

    // Monitor logistics fields for changes to update button status
    const logisticsFieldIds = [
        'modules_per_pallet', 'pallets_per_truck', 'pallet_length_mm', 'pallet_depth_mm',
        'pallet_double_stacked_height_mm', 'pallet_total_weight_kg', 'forklift_truck_long_side_mm',
        'forklift_truck_short_side_mm', 'pallet_jack_long_side_mm', 'pallet_jack_short_side_mm',
        'stacking_in_warehouse', 'stacking_during_transport', 'module_notes'
    ];
    logisticsFieldIds.forEach(fieldId => {
        const el = document.getElementById(fieldId);
        if (el) {
            el.addEventListener('input', mb_updateLogisticsButtonStatus);
        }
    });

    // Initial logistics button status
    mb_updateLogisticsButtonStatus();

    // Module Documentation Panel Event Listeners
    const moduleDocsCloseBtn = document.getElementById('mb_moduleDocsPanelClose');
    const moduleDocsOverlay = document.getElementById('mb_moduleDocsPanelOverlay');
    const moduleDocsDoneBtn = document.getElementById('mb_moduleDocsDoneBtn');
    const moduleDocsClearBtn = document.getElementById('mb_moduleDocsClearBtn');
    const moduleDocsDropArea = document.getElementById('mb_moduleDocsDropArea');
    const moduleDocsFileInput = document.getElementById('module_docs');

    if (moduleDocsCloseBtn) moduleDocsCloseBtn.addEventListener('click', mb_closeModuleDocsPanel);
    if (moduleDocsOverlay) moduleDocsOverlay.addEventListener('click', mb_closeModuleDocsPanel);

    if (moduleDocsDoneBtn) {
        moduleDocsDoneBtn.addEventListener('click', function() {
            // Validate if files are selected but no sub-type
            if (mb_moduleDocsSelectedFiles.length > 0) {
                const subType = document.getElementById('module_docs_sub_type').value;
                if (!subType) {
                    alert('Please select a Document Sub-Type when uploading files.');
                    return;
                }
            }
            mb_closeModuleDocsPanel();
        });
    }

    if (moduleDocsClearBtn) {
        moduleDocsClearBtn.addEventListener('click', function() {
            mb_moduleDocsSelectedFiles = [];
            document.getElementById('module_docs_sub_type').value = '';
            document.getElementById('module_docs_description').value = '';
            mb_updateModuleDocsFileList();
            mb_syncFilesToInput();
            mb_updateModuleDocsButtonStatus();
        });
    }

    // File drop area handling
    if (moduleDocsDropArea) {
        moduleDocsDropArea.addEventListener('click', () => moduleDocsFileInput.click());

        moduleDocsDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            moduleDocsDropArea.style.borderColor = '#5a32a3';
            moduleDocsDropArea.style.background = '#e2d9f3';
        });

        moduleDocsDropArea.addEventListener('dragleave', () => {
            moduleDocsDropArea.style.borderColor = '#6f42c1';
            moduleDocsDropArea.style.background = 'linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%)';
        });

        moduleDocsDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            moduleDocsDropArea.style.borderColor = '#6f42c1';
            moduleDocsDropArea.style.background = 'linear-gradient(135deg, #f8f5fc 0%, #ffffff 100%)';
            if (e.dataTransfer.files.length) {
                mb_handleModuleDocsFiles(e.dataTransfer.files);
            }
        });
    }

    // File input change handler
    if (moduleDocsFileInput) {
        moduleDocsFileInput.addEventListener('change', (e) => {
            if (e.target.files.length) {
                mb_handleModuleDocsFiles(e.target.files);
            }
        });
    }

    // Location change handler for address display
    const locationSelect = document.getElementById('location_id');
    if (locationSelect) {
        locationSelect.addEventListener('change', function() {
            const addressDisplay = document.getElementById('locationAddressDisplay');
            const addressText = document.getElementById('locationAddressText');

            if (this.value && mb_locationDataCache[this.value] && addressDisplay) {
                addressText.textContent = mb_locationDataCache[this.value];
                addressDisplay.style.display = 'flex';
            } else if (addressDisplay) {
                addressDisplay.style.display = 'none';
                addressText.textContent = '';
            }
        });
    }
});
</script>

