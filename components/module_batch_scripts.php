<?php
// Shared JS for Module Batch wizard
// Expected vars in scope: $existingWattages, $existingMilestones, $prefLocationId
?>
<script>
// ========== Location Data Cache ==========
let mb_locationDataCache = {};

// ========== Wattage Field Functions ==========
function mb_addWattageField(wattage = '', quantity = '', domesticPct = '') {
    const container = document.getElementById('wattage-container');
    if (!container) return;
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

    const dGroup = document.createElement('div');
    dGroup.className = 'input-group wattage-domestic-group';
    const dLabel = document.createElement('label');
    dLabel.textContent = 'Domestic Content %';
    const dInput = document.createElement('input');
    dInput.type = 'number'; dInput.step = '0.01'; dInput.min = '0'; dInput.max = '100';
    dInput.name = 'domestic_content_pcts[]'; dInput.placeholder = 'e.g. 45.50';
    if (domesticPct !== '' && domesticPct !== null) dInput.value = domesticPct;
    dGroup.appendChild(dLabel); dGroup.appendChild(dInput);

    const removeButton = document.createElement('button');
    removeButton.type = 'button'; removeButton.textContent = 'Remove'; removeButton.className = 'remove-btn';
    removeButton.onclick = () => container.removeChild(div);

    div.appendChild(wGroup); div.appendChild(qGroup); div.appendChild(dGroup); div.appendChild(removeButton);
    container.appendChild(div);
    mb_toggleDomesticContentFields();
}

function mb_toggleDomesticContentFields() {
    const trackDomestic = document.getElementById('track_domestic_content');
    const isEnabled = !!(trackDomestic && trackDomestic.checked);
    document.querySelectorAll('.wattage-entry').forEach(entry => {
        const domesticGroup = entry.querySelector('.wattage-domestic-group');
        const domesticInput = domesticGroup ? domesticGroup.querySelector('input[name="domestic_content_pcts[]"]') : null;
        if (domesticGroup) domesticGroup.style.display = isEnabled ? '' : 'none';
        if (domesticInput) domesticInput.required = isEnabled;
        entry.classList.toggle('has-domestic', isEnabled);
    });
}

// ========== Manufacturer/Location Functions ==========
function mb_handleManufacturerChange(select) {
    const locationSelect = document.getElementById('location_id');
    const addressDisplay = document.getElementById('locationAddressDisplay');
    const addressText = document.getElementById('locationAddressText');

    if (addressDisplay) { addressDisplay.style.display = 'none'; if (addressText) addressText.textContent = ''; }
    mb_locationDataCache = {};
    if (!locationSelect) return;

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
                    mb_locationDataCache[loc.id] = loc.formatted_address || loc.city || '';
                    const opt = document.createElement('option');
                    opt.value = loc.id;
                    opt.textContent = (loc.location_name ? (loc.location_name + ' \u2014 ') : '') + loc.formatted_address;
                    locationSelect.appendChild(opt);
                });
            } else {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = 'No active locations';
                locationSelect.appendChild(opt);
            }
            locationSelect.disabled = false;
            const pref = '<?php echo isset($prefLocationId) ? (int)$prefLocationId : 0; ?>';
            if (pref && locationSelect.querySelector('option[value="'+pref+'"]')) {
                locationSelect.value = pref;
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

// ========== Module Documentation Functions ==========
let mb_moduleDocsSelectedFiles = [];

function mb_handleModuleDocsFiles(files) {
    const allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'csv'];
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const ext = file.name.split('.').pop().toLowerCase();
        if (!allowedExtensions.includes(ext)) {
            alert('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions.join(', '));
            continue;
        }
        const exists = mb_moduleDocsSelectedFiles.some(f => f.name === file.name && f.size === file.size);
        if (!exists) mb_moduleDocsSelectedFiles.push(file);
    }
    mb_updateModuleDocsFileList();
    mb_syncFilesToInput();
}

function mb_updateModuleDocsFileList() {
    const fileList = document.getElementById('mb_moduleDocsFileList');
    if (!fileList) return;
    if (mb_moduleDocsSelectedFiles.length === 0) { fileList.innerHTML = ''; return; }

    let html = '';
    mb_moduleDocsSelectedFiles.forEach((file, index) => {
        const sizeKB = (file.size / 1024).toFixed(1);
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        const sizeStr = file.size > 1024 * 1024 ? sizeMB + ' MB' : sizeKB + ' KB';
        html += `
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f8f9fa; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e9ecef;">
                <div style="display: flex; align-items: center; gap: 10px; overflow: hidden;">
                    <span style="font-size: 16px;">&#128196;</span>
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
}

function mb_syncFilesToInput() {
    const dt = new DataTransfer();
    mb_moduleDocsSelectedFiles.forEach(file => { dt.items.add(file); });
    const fileInput = document.getElementById('module_docs');
    if (fileInput) fileInput.files = dt.files;
}

// ========== Milestone Functions ==========
let mb_milestones = [];

const mb_triggerEventLabels = {
    'po_execution': 'PO Execution',
    'shipping': 'Shipping',
    'customs_cleared': 'Customs Clearance',
    'project_delivery': 'Project Delivery'
};

function mb_hasPoExecutionMilestone() {
    return mb_milestones.some(m => m.trigger_event === 'po_execution' && parseFloat(m.percentage) > 0);
}

function mb_updatePoExecutionRequirement() {
    const poInput = document.getElementById('po_execution_date');
    const isRequired = mb_hasPoExecutionMilestone();
    if (poInput) poInput.required = isRequired;
}

function mb_getPoExecutionDateValue() {
    const poInput = document.getElementById('po_execution_date');
    return poInput ? poInput.value : '';
}

function mb_toggleInlinePoDate(index) {
    const trigger = mb_milestones[index]?.trigger_event || '';
    const col = document.getElementById('mb_poDateCol_' + index);
    if (col) {
        if (trigger === 'po_execution') {
            col.classList.add('visible');
        } else {
            col.classList.remove('visible');
        }
    }
}

function mb_updatePoDateFromInline(value) {
    const poInput = document.getElementById('po_execution_date');
    if (poInput) poInput.value = value;
    // Sync all other visible inline date fields
    document.querySelectorAll('.milestone-po-date-input').forEach(function(input) {
        input.value = value;
    });
}

function mb_validatePoExecutionDate() {
    const poInput = document.getElementById('po_execution_date');
    if (mb_hasPoExecutionMilestone() && poInput && !poInput.value) {
        alert('PO Execution date is required when a PO Execution milestone is configured.');
        const firstVisible = document.querySelector('.milestone-po-date-row.visible .milestone-po-date-input');
        if (firstVisible) {
            firstVisible.focus();
        }
        return false;
    }
    return true;
}

function mb_getMilestoneTotal() {
    let total = 0;
    mb_milestones.forEach(m => { total += parseFloat(m.percentage) || 0; });
    return total;
}

function mb_validateMilestoneTotal() {
    if (mb_milestones.length === 0) return true;
    const total = mb_getMilestoneTotal();
    if (total !== 100) {
        alert('Milestone percentages must total exactly 100%. Currently: ' + total.toFixed(2).replace(/\.?0+$/, '') + '%');
        return false;
    }
    return true;
}

function mb_ensureDefaultMilestone() {
    const costInput = document.getElementById('cost_per_watt');
    const hasCost = costInput && costInput.value && parseFloat(costInput.value) > 0;
    if (!hasCost || mb_milestones.length > 0) return false;
    mb_milestones.push({ trigger_event: 'project_delivery', percentage: 100 });
    return true;
}

function mb_updateMilestoneAvailability() {
    const costInput = document.getElementById('cost_per_watt');
    const requiresCostMsg = document.getElementById('mb_milestoneRequiresCost');
    const configArea = document.getElementById('mb_milestoneConfigArea');
    const totalArea = document.getElementById('mb_milestoneTotalArea');
    if (!costInput || !requiresCostMsg || !configArea) return;

    const hasCost = costInput.value && parseFloat(costInput.value) > 0;
    if (hasCost) {
        requiresCostMsg.style.display = 'none';
        configArea.style.display = 'block';
        if (totalArea) totalArea.style.display = 'flex';
        const addedDefault = mb_ensureDefaultMilestone();
        if (addedDefault) mb_renderMilestones();
    } else {
        requiresCostMsg.style.display = 'block';
        configArea.style.display = 'none';
        if (totalArea) totalArea.style.display = 'none';
    }
}

function mb_addMilestone(trigger = '', percentage = '') {
    mb_milestones.push({ trigger_event: trigger, percentage: percentage });
    mb_renderMilestones();
}

function mb_removeMilestone(index) {
    mb_milestones.splice(index, 1);
    mb_renderMilestones();
}

function mb_getTriggerTooltip(trigger) {
    const tooltips = {
        'po_execution': 'Triggered when this module batch is added to the project',
        'shipping': 'Triggered when a delivery is created (modules ship from manufacturer)',
        'customs_cleared': 'Triggered when delivery clears customs at port',
        'project_delivery': 'Triggered when delivery arrives at the project site'
    };
    return tooltips[trigger] || '';
}

function mb_updateTriggerInfo(index) {
    const trigger = mb_milestones[index]?.trigger_event || '';
    const tooltip = mb_getTriggerTooltip(trigger);
    const infoEl = document.getElementById('mb_triggerInfo_' + index);
    if (infoEl) infoEl.textContent = tooltip;
}

function mb_renderMilestones() {
    const container = document.getElementById('mb_milestoneContainer');
    if (!container) return;

    if (mb_milestones.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #6c757d; background: #f8f9fa; border-radius: 8px; border: 2px dashed #dee2e6;">No milestones configured yet. Click "Add Milestone" to get started.</div>';
        mb_updateMilestoneTotal();
        mb_syncMilestonesToHiddenInputs();
        return;
    }

    let html = '';
    const currentPoDate = mb_getPoExecutionDateValue();
    mb_milestones.forEach((m, index) => {
        const triggerTooltip = mb_getTriggerTooltip(m.trigger_event);
        const isPoTrigger = m.trigger_event === 'po_execution';
        const poRowClass = 'milestone-po-date-row' + (isPoTrigger ? ' visible' : '');
        html += `
            <div class="milestone-row">
                <div class="milestone-row-inner">
                    <div class="milestone-trigger-col">
                        <label class="milestone-label">Trigger Event</label>
                        <select class="milestone-select" onchange="mb_updateMilestoneData(${index}, 'trigger_event', this.value); mb_updateTriggerInfo(${index}); mb_toggleInlinePoDate(${index})">
                            <option value="">Select event...</option>
                            <option value="po_execution" ${m.trigger_event === 'po_execution' ? 'selected' : ''}>PO Execution</option>
                            <option value="shipping" ${m.trigger_event === 'shipping' ? 'selected' : ''}>Shipping</option>
                            <option value="customs_cleared" ${m.trigger_event === 'customs_cleared' ? 'selected' : ''}>Customs Clearance</option>
                            <option value="project_delivery" ${m.trigger_event === 'project_delivery' ? 'selected' : ''}>Project Delivery</option>
                        </select>
                    </div>
                    <div class="milestone-pct-col">
                        <label class="milestone-label">Percentage</label>
                        <div style="display: flex; align-items: center;">
                            <input type="number" min="0" max="100" step="0.01" value="${m.percentage}" onchange="mb_updateMilestoneData(${index}, 'percentage', this.value)" class="milestone-input">
                            <span style="margin-left: 4px; color: #6c757d;">%</span>
                        </div>
                    </div>
                    <div class="milestone-remove-col">
                        <button type="button" onclick="mb_removeMilestone(${index})" class="milestone-remove-btn">&times;</button>
                    </div>
                </div>
                <div class="${poRowClass}" id="mb_poDateCol_${index}">
                    <label>PO Execution Date:</label>
                    <input type="date" class="milestone-po-date-input" value="${currentPoDate}" onchange="mb_updatePoDateFromInline(this.value)">
                </div>
                <div id="mb_triggerInfo_${index}" class="milestone-trigger-info">${triggerTooltip}</div>
            </div>
        `;
    });

    container.innerHTML = html;
    mb_updateMilestoneTotal();
    mb_updatePoExecutionRequirement();
    mb_syncMilestonesToHiddenInputs();
    // Refresh accordion max-height so content isn't clipped
    mb_refreshAccordionHeight();
}

function mb_updateMilestoneData(index, field, value) {
    if (mb_milestones[index]) {
        mb_milestones[index][field] = value;
        mb_updateMilestoneTotal();
        mb_updatePoExecutionRequirement();
        mb_syncMilestonesToHiddenInputs();
    }
}

function mb_updateMilestoneTotal() {
    let total = 0;
    mb_milestones.forEach(m => { total += parseFloat(m.percentage) || 0; });

    const totalEl = document.getElementById('mb_milestoneTotalPercent');
    const warningEl = document.getElementById('mb_milestonePercentWarning');
    if (totalEl) {
        totalEl.textContent = total.toFixed(2).replace(/\.?0+$/, '') + '%';
        totalEl.style.color = total === 100 ? '#28a745' : (total > 100 ? '#dc3545' : '#17a2b8');
    }
    if (warningEl) {
        warningEl.style.display = (mb_milestones.length > 0 && total !== 100) ? 'block' : 'none';
    }
}

function mb_syncMilestonesToHiddenInputs() {
    const container = document.getElementById('mb_milestoneHiddenInputs');
    if (!container) return;
    container.innerHTML = '';
    mb_milestones.forEach((m, index) => {
        if (m.trigger_event && parseFloat(m.percentage) > 0) {
            container.innerHTML += `<input type="hidden" name="milestones[${index}][trigger_event]" value="${m.trigger_event}">`;
            container.innerHTML += `<input type="hidden" name="milestones[${index}][percentage]" value="${m.percentage}">`;
        }
    });
}

function mb_refreshAccordionHeight() {
    var activeSection = document.querySelector('.accordion-section.active .accordion-content');
    if (activeSection && activeSection.style.maxHeight && activeSection.style.maxHeight !== '0px') {
        activeSection.style.maxHeight = (activeSection.scrollHeight + 200) + 'px';
    }
}

function mb_clearAllMilestones() {
    mb_milestones = [];
    mb_ensureDefaultMilestone();
    mb_renderMilestones();
}

function mb_loadExistingMilestones(existingMilestones) {
    if (Array.isArray(existingMilestones) && existingMilestones.length > 0) {
        mb_milestones = existingMilestones.map(m => ({
            trigger_event: m.trigger_event || '',
            percentage: m.percentage || ''
        }));
        mb_syncMilestonesToHiddenInputs();
        mb_updatePoExecutionRequirement();
    }
}

// ========== DOMContentLoaded Initialization ==========
document.addEventListener('DOMContentLoaded', function() {
    // Load existing wattages
    const existing = <?php echo json_encode($existingWattages ?? []); ?>;
    if (existing.length) {
        existing.forEach(row => mb_addWattageField(
            row.wattage,
            row.quantity,
            (row.domestic_content_pct !== null && row.domestic_content_pct !== undefined) ? row.domestic_content_pct : ''
        ));
    } else {
        mb_addWattageField();
    }

    // Domestic content toggle
    const trackDomestic = document.getElementById('track_domestic_content');
    if (trackDomestic) trackDomestic.addEventListener('change', mb_toggleDomesticContentFields);
    mb_toggleDomesticContentFields();

    // Manufacturer preselect
    const mSel = document.getElementById('manufacturer_id');
    if (mSel && mSel.value) mb_handleManufacturerChange(mSel);

    // Auto-calc modules per truck + bidirectional sync with auto_modules_per_pallet
    const mpp = document.getElementById('modules_per_pallet');
    const ppt = document.getElementById('pallets_per_truck');
    const autoMpp = document.getElementById('auto_modules_per_pallet');
    if (mpp) {
        mpp.addEventListener('input', function() {
            mb_calcModulesPerTruck();
            if (autoMpp && this.value) autoMpp.value = this.value;
        });
    }
    if (ppt) ppt.addEventListener('input', mb_calcModulesPerTruck);
    if (autoMpp) {
        autoMpp.addEventListener('input', function() {
            if (mpp && this.value) mpp.value = this.value;
            mb_calcModulesPerTruck();
        });
    }

    // Location change handler for address display
    const locationSelect = document.getElementById('location_id');
    if (locationSelect) {
        locationSelect.addEventListener('change', function() {
            const addressDisplay = document.getElementById('locationAddressDisplay');
            const addressText = document.getElementById('locationAddressText');
            if (this.value && mb_locationDataCache[this.value] && addressDisplay && addressText) {
                addressText.textContent = mb_locationDataCache[this.value];
                addressDisplay.style.display = 'flex';
            } else if (addressDisplay) {
                addressDisplay.style.display = 'none';
                if (addressText) addressText.textContent = '';
            }
        });
    }

    // File drop area handling
    const moduleDocsDropArea = document.getElementById('mb_moduleDocsDropArea');
    const moduleDocsFileInput = document.getElementById('module_docs');

    if (moduleDocsDropArea && moduleDocsFileInput) {
        moduleDocsDropArea.addEventListener('click', () => moduleDocsFileInput.click());

        moduleDocsDropArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            moduleDocsDropArea.classList.add('dragover');
        });

        moduleDocsDropArea.addEventListener('dragleave', () => {
            moduleDocsDropArea.classList.remove('dragover');
        });

        moduleDocsDropArea.addEventListener('drop', (e) => {
            e.preventDefault();
            moduleDocsDropArea.classList.remove('dragover');
            if (e.dataTransfer.files.length) mb_handleModuleDocsFiles(e.dataTransfer.files);
        });
    }

    if (moduleDocsFileInput) {
        moduleDocsFileInput.addEventListener('change', (e) => {
            if (e.target.files.length) mb_handleModuleDocsFiles(e.target.files);
        });
    }

    // Milestone initialization
    const milestoneAddBtn = document.getElementById('mb_addMilestoneBtn');
    if (milestoneAddBtn) milestoneAddBtn.addEventListener('click', function() { mb_addMilestone(); });

    // Load existing milestones
    const existingMilestones = <?php echo json_encode($existingMilestones ?? []); ?>;
    if (existingMilestones && existingMilestones.length > 0) {
        mb_loadExistingMilestones(existingMilestones);
    }

    // Cost per watt change listener
    const costPerWattInput = document.getElementById('cost_per_watt');
    if (costPerWattInput) costPerWattInput.addEventListener('input', function() {
        mb_updateMilestoneAvailability();
    });

    // Initial milestone state
    mb_updateMilestoneAvailability();
    mb_updatePoExecutionRequirement();
});
</script>
