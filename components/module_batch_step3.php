<?php
// Step 3: Logistics & Documentation
// Expected vars: $module
if (!function_exists('mb_optional_number_value')) {
    function mb_optional_number_value($value) {
        if ($value === null || $value === '') return '';
        if (is_numeric($value) && (float)$value > 0) return htmlspecialchars((string)$value);
        return '';
    }
}
?>

<div class="form-subsection-title">Document Upload</div>
<div class="form-row">
    <div class="form-group">
        <label for="module_docs_sub_type">Document Sub-Type</label>
        <select id="module_docs_sub_type" name="module_docs_sub_type">
            <option value="">Choose sub-type...</option>
            <option value="Module Invoice">Module Invoice</option>
            <option value="Flash Test Data">Flash Test Data</option>
            <option value="Spec Sheets">Spec Sheets</option>
        </select>
        <span class="help-text">Required only when uploading files.</span>
    </div>
    <div class="form-group">
        <label for="module_docs_description">Description <span class="optional-tag">(Optional)</span></label>
        <input type="text" id="module_docs_description" name="module_docs_description" placeholder="Brief description of documents">
    </div>
</div>
<div id="mb_moduleDocsDropArea" class="file-drop-zone">
    <div style="font-size: 36px; color: #6f42c1; margin-bottom: 12px;">&#128206;</div>
    <div style="font-size: 0.95rem; font-weight: 600; color: #293E4C; margin-bottom: 6px;">Drop files here or click to browse</div>
    <div style="font-size: 0.8rem; color: #6c757d;">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT, CSV</div>
</div>
<input type="file" id="module_docs" name="module_docs[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv">
<div id="mb_moduleDocsFileList" style="margin-top: 16px;"></div>

<div class="form-subsection-title" style="margin-top: 32px;">Handling, Stacking &amp; Notes <span style="font-weight: 400; font-size: 0.85rem; color: #6c757d;">(optional)</span></div>
<div class="form-row">
    <div class="form-group">
        <label for="forklift_truck_long_side_mm">Forklift Long Side (mm)</label>
        <input type="number" id="forklift_truck_long_side_mm" name="forklift_truck_long_side_mm" min="1" placeholder="e.g. 1200" value="<?php echo mb_optional_number_value($module['forklift_truck_long_side_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="forklift_truck_short_side_mm">Forklift Short Side (mm)</label>
        <input type="number" id="forklift_truck_short_side_mm" name="forklift_truck_short_side_mm" min="1" placeholder="e.g. 1000" value="<?php echo mb_optional_number_value($module['forklift_truck_short_side_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallet_jack_long_side_mm">Pallet Jack Long Side (mm)</label>
        <input type="number" id="pallet_jack_long_side_mm" name="pallet_jack_long_side_mm" min="1" placeholder="e.g. 1150" value="<?php echo mb_optional_number_value($module['pallet_jack_long_side_mm'] ?? null); ?>">
    </div>
    <div class="form-group">
        <label for="pallet_jack_short_side_mm">Pallet Jack Short Side (mm)</label>
        <input type="number" id="pallet_jack_short_side_mm" name="pallet_jack_short_side_mm" min="1" placeholder="e.g. 800" value="<?php echo mb_optional_number_value($module['pallet_jack_short_side_mm'] ?? null); ?>">
    </div>
</div>
<div class="form-row">
    <div class="form-group">
        <label for="stacking_in_warehouse">Warehouse Stacking</label>
        <textarea id="stacking_in_warehouse" name="stacking_in_warehouse" rows="2" placeholder="Instructions for warehouse stacking..."><?php echo htmlspecialchars($module['stacking_in_warehouse'] ?? ''); ?></textarea>
    </div>
    <div class="form-group">
        <label for="stacking_during_transport">Transport Stacking</label>
        <textarea id="stacking_during_transport" name="stacking_during_transport" rows="2" placeholder="Instructions for transport stacking..."><?php echo htmlspecialchars($module['stacking_during_transport'] ?? ''); ?></textarea>
    </div>
    <div class="form-group">
        <label for="module_notes">Module Notes</label>
        <textarea id="module_notes" name="module_notes" rows="2" placeholder="General notes about the modules..."><?php echo htmlspecialchars($module['module_notes'] ?? ''); ?></textarea>
    </div>
</div>
