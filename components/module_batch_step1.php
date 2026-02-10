<?php
// Step 1: Manufacturer & Modules
// Expected vars: $manufacturers, $prefManufacturerId, $prefLocationId, $existingWattages, $module

if (!function_exists('mb_optional_number_value')) {
    function mb_optional_number_value($value) {
        if ($value === null || $value === '') return '';
        if (is_numeric($value) && (float)$value > 0) return htmlspecialchars((string)$value);
        return '';
    }
}

$mb_track_domestic_default = !empty($_POST['track_domestic_content']);
if (!$mb_track_domestic_default && !empty($existingWattages) && is_array($existingWattages)) {
    foreach ($existingWattages as $existingRow) {
        if (isset($existingRow['domestic_content_pct']) && $existingRow['domestic_content_pct'] !== null && $existingRow['domestic_content_pct'] !== '') {
            $mb_track_domestic_default = true;
            break;
        }
    }
}
?>

<div class="input-group" style="margin-bottom: 20px;">
    <label for="batch_name">Batch Name <span class="optional-tag">(Optional)</span></label>
    <input type="text" name="batch_name" id="batch_name"
           value="<?php echo htmlspecialchars($module['batch_name'] ?? ''); ?>"
           placeholder="e.g. Q1 Delivery, Phase 2 Panels...">
</div>

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
                <span class="address-icon">&#128205;</span>
                <span id="locationAddressText"></span>
            </div>
        </div>
    </div>

    <!-- Right Column: Wattage & Quantities -->
    <div class="mb-right-column">
        <div class="input-group" style="margin-bottom: 0;">
            <label>Wattage & Quantities</label>
            <label class="mb-domestic-toggle" for="track_domestic_content">
                <input type="checkbox" id="track_domestic_content" name="track_domestic_content" value="1" <?php echo $mb_track_domestic_default ? 'checked' : ''; ?>>
                Track Domestic Content %
            </label>
            <div id="wattage-container" class="wattage-container"></div>
            <button type="button" class="add-wattage-btn" onclick="mb_addWattageField()">+ Add Wattage</button>
        </div>
    </div>
</div>
