<?php
// Step 2: Pricing & Milestones
// Expected vars: $module, $existingMilestones
?>

<div class="form-subsection-title">Cost Per Watt</div>
<div class="form-row">
    <div class="form-group">
        <label for="cost_per_watt">Price per Watt ($/W)</label>
        <input type="number" step="0.000001" min="0" name="cost_per_watt" id="cost_per_watt"
               placeholder="e.g. 0.25"
               value="<?php echo htmlspecialchars($module['cost_per_watt'] ?? ''); ?>"
               oninput="mb_updateMilestoneAvailability()" style="max-width: 240px;">
        <span class="help-text">Enter the module cost for reporting and milestone calculations.</span>
    </div>
</div>

<div class="form-subsection-title">Payment Milestones <span style="font-weight: 400; font-size: 0.85rem; color: #6c757d;">(optional)</span></div>
<p style="margin: 8px 0 16px 0; color: #6c757d; font-size: 0.85rem;">
    Configure when payments are triggered based on delivery events.
    Payment = <strong>cost per watt &times; wattage &times; quantity &times; percentage</strong>
</p>
<div id="mb_milestoneRequiresCost" style="display: none; padding: 12px 16px; background: #f8f9fa; border: 1px dashed #dee2e6; border-radius: 8px; color: #6c757d; font-size: 13px; text-align: center;">
    Enter a cost per watt above to configure payment milestones.
</div>
<div id="mb_milestoneConfigArea">
    <div id="mb_milestoneContainer"></div>
    <button type="button" id="mb_addMilestoneBtn" style="margin-top: 12px; padding: 10px 16px; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500;">
        + Add Milestone
    </button>
</div>

<!-- Total Percentage Display -->
<div id="mb_milestoneTotalArea" style="margin-top: 20px; padding: 12px 16px; background: #f8f9fa; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
    <span style="font-weight: 500; color: #293E4C;">Total Percentage:</span>
    <span id="mb_milestoneTotalPercent" style="font-weight: 600; font-size: 1.1rem; color: #17a2b8;">0%</span>
</div>
<div id="mb_milestonePercentWarning" style="display: none; margin-top: 8px; padding: 8px 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; color: #856404; font-size: 12px;">
    Note: Total percentage does not equal 100%. This is allowed but may indicate incomplete milestone configuration.
</div>

<!-- Hidden inputs for milestone data -->
<div id="mb_milestoneHiddenInputs"></div>
<input type="hidden" name="po_execution_date" id="po_execution_date"
       value="<?php echo htmlspecialchars($module['po_execution_date'] ?? ''); ?>">
