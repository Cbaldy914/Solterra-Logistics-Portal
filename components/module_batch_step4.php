<?php
// Step 4: Palletization
// Expected vars: $module
if (!function_exists('mb_optional_number_value')) {
    function mb_optional_number_value($value) {
        if ($value === null || $value === '') return '';
        if (is_numeric($value) && (float)$value > 0) return htmlspecialchars((string)$value);
        return '';
    }
}
?>

<div class="form-subsection-title">Truck & Pallet Info</div>
<div class="form-row">
    <div class="form-group">
        <label for="modules_per_pallet">Modules per Pallet</label>
        <input type="number" id="modules_per_pallet" name="modules_per_pallet" min="1" placeholder="e.g. 30" value="<?php echo mb_optional_number_value($module['modules_per_pallet'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallets_per_truck">Pallets per Truck</label>
        <input type="number" id="pallets_per_truck" name="pallets_per_truck" min="1" placeholder="e.g. 22" value="<?php echo mb_optional_number_value($module['pallets_per_truck'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="modules_per_truck">Modules per Truck</label>
        <input type="number" id="modules_per_truck" name="modules_per_truck" min="1" placeholder="Auto-calculated" readonly value="<?php echo mb_optional_number_value($module['modules_per_truck'] ?? null); ?>">
        <span class="help-text">Auto-calculated from modules/pallet &times; pallets/truck</span>
    </div>
</div>

<div class="form-subsection-title">Pallet Dimensions</div>
<div class="form-row">
    <div class="form-group">
        <label for="pallet_length_mm">Length (mm)</label>
        <input type="number" id="pallet_length_mm" name="pallet_length_mm" min="1" placeholder="e.g. 2384" value="<?php echo mb_optional_number_value($module['pallet_length_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallet_depth_mm">Depth (mm)</label>
        <input type="number" id="pallet_depth_mm" name="pallet_depth_mm" min="1" placeholder="e.g. 1303" value="<?php echo mb_optional_number_value($module['pallet_depth_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallet_double_stacked_height_mm">Stack Height (mm)</label>
        <input type="number" id="pallet_double_stacked_height_mm" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g. 2200" value="<?php echo mb_optional_number_value($module['pallet_double_stacked_height_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallet_total_weight_kg">Weight (kg)</label>
        <input type="number" id="pallet_total_weight_kg" name="pallet_total_weight_kg" min="1" placeholder="e.g. 1200" value="<?php echo mb_optional_number_value($module['pallet_total_weight_kg'] ?? null); ?>">
    </div>
</div>

<div id="autoPalletizationMountPoint"></div>
