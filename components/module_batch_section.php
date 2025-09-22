<?php
// Shared Module Batch Section (UI + JS) used by add_module_batch.php and edit_module_batch.php
// Expected variables in scope:
// - $manufacturers: array of ['id'=>int,'name'=>string]
// - $prefManufacturerId: int|null (optional preselect)
// - $prefLocationId: int|null (optional preselect)
// - $existingWattages: array of ['wattage'=>int,'quantity'=>int] (optional for edit)
?>

<style>
    .module-section { grid-column: 1 / -1; border-top: 1px solid #f0f0f0; margin-top: 20px; padding-top: 40px; }
    .module-intro { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 24px; border-radius: 12px; margin-bottom: 32px; text-align: center; }
    .module-intro h3 { color: #293E4C; margin-bottom: 8px; font-weight: 600; }
    .module-intro p { color: #666; margin-bottom: 0; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .form-section { padding: 0; border: none; background: transparent; }
    .form-section h2 { font-size: 1.2rem; font-weight: 600; color: #1a1a1a; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #488C9A; }
    .input-group { margin-bottom: 16px; }
    .input-group label { display: block; font-weight: 500; color: #333; margin-bottom: 8px; font-size: 0.95rem; }
    .input-group input, .input-group select, .input-group textarea { width: 100%; padding: 12px 16px; border: 2px solid #e8e8e8; border-radius: 8px; font-size: 1rem; transition: all 0.2s ease; box-sizing: border-box; background: #fafafa; }
    .input-group input:focus, .input-group select:focus, .input-group textarea:focus { outline: none; border-color: #488C9A; background: #fff; box-shadow: 0 0 0 3px rgba(72,140,154,0.1); }
    .wattage-container { margin: 16px 0; }
    .wattage-entry { display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: end; padding: 16px; background: #f8f9fa; border-radius: 12px; margin-bottom: 12px; border: 1px solid #e9ecef; }
    .remove-btn { background: #dc3545; color: #fff; border: none; padding: 10px 14px; border-radius: 8px; cursor: pointer; font-weight: 500; }
    .add-wattage-btn { background: #488C9A; color: #fff; border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-weight: 500; margin-bottom: 12px; }
    .specs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 16px 0; }
    @media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
</style>

<div class="module-section">
    <div class="module-intro">
        <h3>Initial Module Batch</h3>
        <p>Select manufacturer/location, add wattage items, and logistics specs.</p>
    </div>

    <div class="form-grid">
        <div class="form-section">
            <h2>Manufacturer & Modules</h2>
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

            <div class="input-group">
                <label for="location_id">Location</label>
                <select name="location_id" id="location_id" <?php echo empty($prefManufacturerId) ? 'disabled' : ''; ?>>
                    <option value="">Select a manufacturer first</option>
                </select>
            </div>

            <div class="input-group">
                <label>Wattage & Quantities</label>
                <div id="wattage-container" class="wattage-container"></div>
                <button type="button" class="add-wattage-btn" onclick="mb_addWattageField()">+ Add Wattage</button>
            </div>
        </div>

        <div class="form-section">
            <h2>Logistics Specifications <span style="color: #999; font-weight: 400; font-size: 0.85rem;">(optional)</span></h2>
            <div class="specs-grid">
                <div class="input-group">
                    <label for="modules_per_pallet">Modules per Pallet</label>
                    <input type="number" id="modules_per_pallet" name="modules_per_pallet" min="1" placeholder="e.g. 30">
                </div>
                <div class="input-group">
                    <label for="pallets_per_truck">Pallets per Truck</label>
                    <input type="number" id="pallets_per_truck" name="pallets_per_truck" min="1" placeholder="e.g. 22">
                </div>
                <div class="input-group">
                    <label for="modules_per_truck">Modules per Truck</label>
                    <input type="number" id="modules_per_truck" name="modules_per_truck" min="1" placeholder="Auto-calculated" readonly style="background-color: #f8f9fa; color: #6c757d;">
                </div>
                <div class="input-group"><label for="pallet_length_mm">Length (mm)</label><input type="number" id="pallet_length_mm" name="pallet_length_mm" min="1" placeholder="e.g. 2384"></div>
                <div class="input-group"><label for="pallet_depth_mm">Depth (mm)</label><input type="number" id="pallet_depth_mm" name="pallet_depth_mm" min="1" placeholder="e.g. 1303"></div>
                <div class="input-group"><label for="pallet_double_stacked_height_mm">Stack Height (mm)</label><input type="number" id="pallet_double_stacked_height_mm" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g. 2200"></div>
                <div class="input-group"><label for="pallet_total_weight_kg">Weight (kg)</label><input type="number" id="pallet_total_weight_kg" name="pallet_total_weight_kg" min="1" placeholder="e.g. 1200"></div>
                <div class="input-group"><label for="forklift_truck_long_side_mm">Forklift Long (mm)</label><input type="number" id="forklift_truck_long_side_mm" name="forklift_truck_long_side_mm" min="1" placeholder="e.g. 1200"></div>
                <div class="input-group"><label for="forklift_truck_short_side_mm">Forklift Short (mm)</label><input type="number" id="forklift_truck_short_side_mm" name="forklift_truck_short_side_mm" min="1" placeholder="e.g. 1000"></div>
                <div class="input-group"><label for="pallet_jack_long_side_mm">Pallet Jack Long (mm)</label><input type="number" id="pallet_jack_long_side_mm" name="pallet_jack_long_side_mm" min="1" placeholder="e.g. 1150"></div>
                <div class="input-group"><label for="pallet_jack_short_side_mm">Pallet Jack Short (mm)</label><input type="number" id="pallet_jack_short_side_mm" name="pallet_jack_short_side_mm" min="1" placeholder="e.g. 800"></div>
            </div>
            <div class="input-group">
                <label for="stacking_in_warehouse">Warehouse Stacking</label>
                <textarea id="stacking_in_warehouse" name="stacking_in_warehouse" placeholder="e.g. Instructions for warehouse stacking..."></textarea>
            </div>
            <div class="input-group">
                <label for="stacking_during_transport">Transport Stacking</label>
                <textarea id="stacking_during_transport" name="stacking_during_transport" placeholder="e.g. Instructions for transport stacking..."></textarea>
            </div>
            <div class="input-group">
                <label for="module_notes">Module Notes</label>
                <textarea id="module_notes" name="module_notes" placeholder="e.g. General notes about the modules..."></textarea>
            </div>
        </div>
    </div>
</div>

<script>
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
    wInput.type = 'number'; wInput.step = '1'; wInput.name = 'wattages['+index+']'; wInput.required = true; wInput.placeholder = 'e.g. 555';
    if (wattage !== '') wInput.value = wattage;
    wGroup.appendChild(wLabel); wGroup.appendChild(wInput);

    const qGroup = document.createElement('div');
    qGroup.className = 'input-group';
    const qLabel = document.createElement('label');
    qLabel.textContent = 'Quantity';
    const qInput = document.createElement('input');
    qInput.type = 'number'; qInput.step = '1'; qInput.name = 'quantities['+index+']'; qInput.required = true; qInput.placeholder = 'e.g. 1000';
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
});
</script>

