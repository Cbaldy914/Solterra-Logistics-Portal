<script>
        // ==================== GLOBAL STATE ====================
        const projectId = <?php echo $project_id; ?>;
        const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
        const projectInfo = {
            name: <?php echo json_encode($project['project_name']); ?>,
            address: <?php echo json_encode($project['project_address']); ?>,
            modulesPerPallet: <?php echo (int)($project_summary['modules_per_pallet'] ?? 30); ?>,
            palletsPerTruck: <?php echo (int)($project_summary['pallets_per_truck'] ?? 20); ?>,
            totalTrucks: <?php echo (float)($project_summary['trucks'] ?? 0); ?>
        };

        let currentProjection = <?php echo $current_projection ? json_encode($current_projection) : 'null'; ?>;
        let projections = <?php echo json_encode($projections); ?>;
        let availableBatches = <?php echo json_encode($available_batches); ?>;
        const costSummary = <?php echo json_encode($cost_summary); ?> || {};

        // Working state (modified by user before save)
        let workingState = {
            projectionId: currentProjection?.id || null,
            projectionName: currentProjection?.projection_name || 'Default Projection',
            status: currentProjection?.status || 'draft',
            notes: currentProjection?.notes || '',
            isPrimary: currentProjection?.is_primary || false,
            poExecutionDate: <?php echo json_encode($current_projection['po_execution_date'] ?? ''); ?>,
            moduleAllocations: <?php echo json_encode($allocated_modules); ?> || [],
            stops: <?php echo json_encode($stops); ?> || [],
            legs: <?php echo json_encode($legs); ?> || []
        };

        const stepperSections = ['modules-costs', 'logistics-plan', 'timeline'];

        // Flatpickr instances
        let datePickerInstances = [];

        // Google Places autocomplete instances
        let autocompleteInstances = [];

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initializeDatePickers();
            updateUIFromState();
            renderJourneyPlan();
            initializeMap();
            updateTimelineChart();
            loadCollapsibleStates();
            openTimelineSectionFromLink();
            checkStoredNavigation();

            // Redraw flow connections on window resize
            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    if (typeof renderFlowConnections === 'function') {
                        renderFlowConnections();
                    }
                }, 150);
            });
        });

        function openTimelineSectionFromLink() {
            const params = new URLSearchParams(window.location.search);
            const target = params.get('section');
            const tab = params.get('tab');
            const view = params.get('view');
            const hash = window.location.hash.replace('#', '');

            // Handle view=weekly-projections parameter (from project overview)
            if (view === 'weekly-projections') {
                // Open timeline section
                const header = document.querySelector('[data-section="timeline"] .collapsible-header');
                const content = document.getElementById('timeline-content');
                if (header && content) {
                    header.classList.remove('collapsed');
                    content.classList.remove('collapsed');
                }

                // Switch to Monthly Forecast tab which contains the weekly projections table
                setTimeout(() => {
                    if (typeof switchTimelineTab === 'function') {
                        switchTimelineTab('line-chart');
                    }

                    // Expand the weekly projections section
                    const weeklySection = document.getElementById('weeklyProjectionsSection');
                    const weeklyContent = document.getElementById('weeklyProjectionsContent');
                    if (weeklySection && weeklyContent) {
                        weeklySection.classList.remove('collapsed');
                        weeklyContent.classList.remove('collapsed');
                    }

                    // Scroll to weekly projections
                    setTimeout(() => {
                        const weeklyTable = document.getElementById('weeklyProjectionsSection');
                        if (weeklyTable) {
                            weeklyTable.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 100);
                }, 100);

                return;
            }

            if (target !== 'timeline' && hash !== 'timeline') {
                return;
            }

            const header = document.querySelector('[data-section="timeline"] .collapsible-header');
            const content = document.getElementById('timeline-content');
            if (header && content) {
                header.classList.remove('collapsed');
                content.classList.remove('collapsed');
            }

            const section = document.querySelector('[data-section="timeline"]');
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            if (tab && typeof switchTimelineTab === 'function') {
                switchTimelineTab(tab);
            }
        }

        // ==================== COLLAPSIBLE SECTIONS ====================
        function toggleSection(sectionId) {
            const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
            const content = document.getElementById(`${sectionId}-content`);

            if (!header || !content) return;

            const isCollapsed = header.classList.contains('collapsed');

            if (isCollapsed) {
                // Opening this section - collapse all others first
                stepperSections.forEach(otherId => {
                    if (otherId !== sectionId) {
                        const otherHeader = document.querySelector(`[data-section="${otherId}"] .collapsible-header`);
                        const otherContent = document.getElementById(`${otherId}-content`);
                        if (otherHeader && otherContent) {
                            otherHeader.classList.add('collapsed');
                            otherContent.classList.add('collapsed');
                        }
                    }
                });
                header.classList.remove('collapsed');
                content.classList.remove('collapsed');
                // Update stepper to show this section as active
                updateStepperState(sectionId);
            } else {
                // Closing this section
                header.classList.add('collapsed');
                content.classList.add('collapsed');
                // Update stepper to show no section as active (all collapsed)
                updateStepperState(null);
            }

            // Save state to localStorage
            saveCollapsibleStates();

            // If opening the logistics section with map view active, trigger a resize
            if (sectionId === 'logistics-plan' && isCollapsed && map) {
                const mapView = document.getElementById('logistics-map-view');
                if (mapView && mapView.classList.contains('active')) {
                    setTimeout(() => {
                        google.maps.event.trigger(map, 'resize');
                        updateMapFromState();
                    }, 350);
                }
            }
        }

        function saveCollapsibleStates() {
            const states = {};
            document.querySelectorAll('.collapsible-section').forEach(section => {
                const sectionId = section.dataset.section;
                const header = section.querySelector('.collapsible-header');
                states[sectionId] = header.classList.contains('collapsed');
            });
            localStorage.setItem(`projection_collapsed_${projectId}`, JSON.stringify(states));
        }

        function loadCollapsibleStates() {
            // Collapse all sections by default
            document.querySelectorAll('.collapsible-section').forEach(section => {
                const header = section.querySelector('.collapsible-header');
                const sectionId = section.dataset.section;
                const content = document.getElementById(`${sectionId}-content`);
                if (header && content) {
                    header.classList.add('collapsed');
                    content.classList.add('collapsed');
                }
            });

            localStorage.removeItem(`projection_collapsed_${projectId}`);

            // Update stepper to show no active step since all are collapsed
            updateStepperState(null);
        }

        function toggleWeeklyProjections() {
            const section = document.getElementById('weeklyProjectionsSection');
            const content = document.getElementById('weeklyProjectionsContent');
            if (!section || !content) return;

            const isCollapsed = section.classList.contains('collapsed');
            if (isCollapsed) {
                section.classList.remove('collapsed');
                content.classList.remove('collapsed');
            } else {
                section.classList.add('collapsed');
                content.classList.add('collapsed');
            }
        }

        // ==================== GOOGLE PLACES AUTOCOMPLETE ====================
        function initializeAddressAutocomplete(inputElement, onPlaceSelected) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
                console.log('Google Places API not available');
                return null;
            }

            const autocomplete = new google.maps.places.Autocomplete(inputElement, {
                types: ['address'],
                fields: ['formatted_address', 'geometry', 'address_components', 'name']
            });

            // Prevent form submission on enter key in autocomplete
            inputElement.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            autocomplete.addListener('place_changed', function() {
                const place = autocomplete.getPlace();

                if (!place.geometry) {
                    // User entered text that is not a place
                    inputElement.parentElement.classList.add('error');
                    inputElement.parentElement.classList.remove('verified');
                    return;
                }

                inputElement.parentElement.classList.remove('error');
                inputElement.parentElement.classList.add('verified');

                // Explicitly set the input value to the formatted address
                const formattedAddress = place.formatted_address || inputElement.value;
                inputElement.value = formattedAddress;

                const placeData = {
                    address: formattedAddress,
                    latitude: place.geometry.location.lat(),
                    longitude: place.geometry.location.lng(),
                    name: place.name || ''
                };

                if (onPlaceSelected) {
                    onPlaceSelected(placeData);
                }

                // Trigger change event to update state
                inputElement.dispatchEvent(new Event('change', { bubbles: true }));
            });

            autocompleteInstances.push(autocomplete);
            return autocomplete;
        }

        function cleanupAutocompleteInstances() {
            // Remove pac-container elements that are orphaned
            document.querySelectorAll('.pac-container').forEach(el => {
                if (!document.body.contains(el.closest('.address-input-wrapper'))) {
                    el.remove();
                }
            });
            autocompleteInstances = [];
        }

        function initializeDatePickers() {
            // Clean up existing instances
            datePickerInstances.forEach(fp => fp.destroy());
            datePickerInstances = [];

            // Initialize new pickers
            document.querySelectorAll('.flatpickr-date').forEach(input => {
                const fp = flatpickr(input, {
                    dateFormat: 'Y-m-d',
                    allowInput: true,
                    onChange: function(selectedDates, dateStr) {
                        // Auto-calculate end date when start date changes
                        if (input.id === 'legStartDate' && typeof autoCalcEndDate === 'function') {
                            autoCalcEndDate();
                        }
                    }
                });
                datePickerInstances.push(fp);
            });
        }

        function updateUIFromState() {
            // Update projection selector
            const selector = document.getElementById('projectionSelector');
            if (selector && workingState.projectionId) {
                selector.value = workingState.projectionId;
            }

            // Update project allocation tracker
            if (typeof updateProjectAllocationTracker === 'function') {
                updateProjectAllocationTracker();
            }

            updateStepperState();
        }

        // ==================== PROJECTION MANAGEMENT ====================
        function loadProjection(projectionId) {
            if (projectionId === 'new') {
                createNewProjection();
                return;
            }

            showLoading('Loading projection...');

            fetch(`api/projection_load.php?projection_id=${projectionId}`)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        currentProjection = data.projection;
                        workingState = {
                            projectionId: data.projection.id,
                            projectionName: data.projection.projection_name,
                            status: data.projection.status,
                            notes: data.projection.notes || '',
                            isPrimary: data.projection.is_primary,
                            moduleAllocations: data.projection.module_allocations || [],
                            stops: data.projection.stops || [],
                            legs: data.projection.legs || []
                        };
                        availableBatches = data.available_batches || [];
                        // Reload page to update components
                        window.location.href = `anticipated_deliveries.php?project_id=${projectId}&projection_id=${projectionId}`;
                    } else {
                        showToast('Failed to load projection: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showToast('Error loading projection', 'error');
                    console.error(error);
                });
        }

        function createNewProjection() {
            if (!canEdit) {
                showToast('You do not have permission to create projections', 'error');
                return;
            }

            const name = prompt('Enter a name for the new projection:', 'New Projection');
            if (!name) return;

            showLoading('Creating projection...');

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: projectId,
                    projection_name: name,
                    is_primary: projections.length === 0
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Projection created successfully', 'success');
                    window.location.href = `anticipated_deliveries.php?project_id=${projectId}&projection_id=${data.projection_id}`;
                } else {
                    showToast('Failed to create projection: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error creating projection', 'error');
                console.error(error);
            });
        }

        function saveProjection() {
            if (!canEdit) {
                showToast('You do not have permission to save', 'error');
                return;
            }

            if (!workingState.projectionId) {
                showToast('Please create a projection first', 'error');
                return;
            }

            if (!validateModulePoDates()) {
                return;
            }

            syncPlanState();
            showLoading('Saving projection...');

            const payload = {
                project_id: projectId,
                projection_id: workingState.projectionId,
                projection_name: workingState.projectionName,
                status: workingState.status,
                notes: workingState.notes,
                is_primary: workingState.isPrimary,
                po_execution_date: workingState.poExecutionDate || null,
                module_allocations: workingState.moduleAllocations.map(a => ({
                    module_id: a.module_id,
                    wattage: a.wattage,
                    quantity: a.quantity,
                    pallets: a.pallets,
                    po_execution_date: a.po_execution_date || null,
                    milestones: a.milestones || [],
                    // Include additional fields for manual entries
                    vendor_name: a.vendor_name,
                    manufacturer_address: a.manufacturer_address,
                    modules_per_pallet: a.modules_per_pallet,
                    pallets_per_truck: a.pallets_per_truck,
                    cost_per_watt: a.cost_per_watt,
                    is_manual: a.is_manual || false
                })),
                stops: workingState.stops.map((s, i) => ({
                    id: s.id,
                    stop_type: s.stop_type,
                    location_name: s.location_name,
                    location_address: s.location_address,
                    latitude: s.latitude,
                    longitude: s.longitude,
                    warehouse_id: s.warehouse_id,
                    is_customs_clearance: s.is_customs_clearance ? 1 : 0,
                    estimated_arrival_date: s.estimated_arrival_date,
                    estimated_departure_date: s.estimated_departure_date,
                    notes: s.notes,
                    fees: s.fees || []
                })),
                legs: workingState.legs.map(l => ({
                    id: l.id,
                    from_stop_id: l.from_stop_id,
                    to_stop_id: l.to_stop_id,
                    transport_mode: l.transport_mode,
                    start_date: l.start_date,
                    end_date: l.end_date,
                    delivery_rate: l.delivery_rate,
                    delivery_rate_unit: l.delivery_rate_unit,
                    trucks_required: l.trucks_required,
                    freight_cost_per_truck: l.freight_cost_per_truck,
                    accessorial_cost_per_truck: l.accessorial_cost_per_truck,
                    total_freight_cost: l.total_freight_cost,
                    triggers_milestone: l.triggers_milestone,
                    notes: l.notes
                }))
            };

            fetch('api/projection_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(async response => {
                const text = await response.text();
                if (!text) {
                    throw new Error('Empty response from server');
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    throw new Error(text);
                }
                if (!response.ok) {
                    throw new Error(data.error || data.message || `Server error (${response.status})`);
                }
                return data;
            })
            .then(data => {
                if (data.success) {
                    markAsSaved();
                    // If navigating to another section after save, keep spinner and reload immediately
                    if (sessionStorage.getItem('navigateToSection')) {
                        window.location.reload();
                    } else {
                        hideLoading();
                        showToast('Projection saved successfully', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    hideLoading();
                    showToast('Failed to save: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error saving projection: ' + error.message, 'error');
                console.error(error);
            });
        }

        function deleteProjection() {
            if (!canEdit || !workingState.projectionId) return;

            if (!confirm('Are you sure you want to delete this projection? This cannot be undone.')) {
                return;
            }

            showLoading('Deleting projection...');

            fetch('api/projection_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ projection_id: workingState.projectionId })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast('Projection deleted', 'success');
                    window.location.href = `anticipated_deliveries.php?project_id=${projectId}`;
                } else {
                    showToast('Failed to delete: ' + data.error, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Error deleting projection', 'error');
            });
        }

        function editProjectionName() {
            const newName = prompt('Enter new projection name:', workingState.projectionName);
            if (newName && newName !== workingState.projectionName) {
                workingState.projectionName = newName;
                // Update selector display
                const selector = document.getElementById('projectionSelector');
                if (selector) {
                    const option = selector.querySelector(`option[value="${workingState.projectionId}"]`);
                    if (option) {
                        option.textContent = newName + (workingState.isPrimary ? ' (Primary)' : '');
                    }
                }
                showToast('Name updated. Remember to save!', 'info');
            }
        }

        function setAsPrimary() {
            workingState.isPrimary = true;
            saveProjection();
        }

        // ==================== MODULE ALLOCATION ====================

        // Direct batch selection - no modal, just add it
        function selectModuleBatch(batchId, wattagesStr, totalQuantity, modsPerPallet, palletsPerTruck) {
            // Check if already added
            if (workingState.moduleAllocations.some(allocation => String(allocation.module_id) === String(batchId))) {
                showToast('This batch is already added to the projection.', 'error');
                return;
            }

            const batch = availableBatches.find(b => b.id == batchId);
            if (!batch) {
                showToast('Module batch not found', 'error');
                return;
            }

            // Use first wattage if multiple, or parse the wattages string
            const wattages = wattagesStr.split(',').map(w => parseInt(w.trim()));
            const wattage = wattages[0] || batch.wattage || 560;

            // Use total quantity from batch
            const quantity = totalQuantity || batch.total_quantity;

            // Calculate pallets
            const modulesPerPallet = modsPerPallet || batch.modules_per_pallet || 30;
            const palletsPerTruckVal = palletsPerTruck || batch.pallets_per_truck || 20;
            const pallets = Math.ceil(quantity / modulesPerPallet);

            // Calculate contract value
            const totalWatts = wattage * quantity;
            const contractValue = batch.cost_per_watt ? (batch.cost_per_watt * totalWatts) : 0;

            const allocation = {
                module_id: batchId,
                wattage: parseInt(wattage),
                quantity: parseInt(quantity),
                pallets: pallets,
                modules_per_pallet: modulesPerPallet,
                pallets_per_truck: palletsPerTruckVal,
                vendor_name: batch.vendor_name,
                manufacturer_name: batch.manufacturer_name,
                manufacturer_address: batch.manufacturer_address,
                cost_per_watt: batch.cost_per_watt,
                contract_value: contractValue,
                has_milestones: batch.has_milestones,
                milestones: batch.milestones || []
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Module batch added!', 'success');

            // Hide this batch from the available list
            const batchItem = document.querySelector(`.batch-item[data-batch-id="${batchId}"]`);
            if (batchItem) {
                batchItem.style.display = 'none';
            }

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
            updateStepperState();
        }

        function addModuleAllocation(batchId, wattage, quantity) {
            if (workingState.moduleAllocations.some(allocation => String(allocation.module_id) === String(batchId))) {
                showToast('This batch is already added to the projection.', 'error');
                return;
            }

            const batch = availableBatches.find(b => b.id == batchId);
            if (!batch) {
                showToast('Module batch not found', 'error');
                return;
            }

            // Calculate pallets
            const modulesPerPallet = batch.modules_per_pallet || 30;
            const palletsPerTruck = batch.pallets_per_truck || 20;
            const pallets = Math.ceil(quantity / modulesPerPallet);

            // Calculate contract value
            const totalWatts = wattage * quantity;
            const contractValue = batch.cost_per_watt ? (batch.cost_per_watt * totalWatts) : 0;

            const allocation = {
                module_id: batchId,
                wattage: parseInt(wattage),
                quantity: parseInt(quantity),
                pallets: pallets,
                modules_per_pallet: modulesPerPallet,
                pallets_per_truck: palletsPerTruck,
                vendor_name: batch.vendor_name,
                manufacturer_name: batch.manufacturer_name,
                manufacturer_address: batch.manufacturer_address,
                cost_per_watt: batch.cost_per_watt,
                contract_value: contractValue,
                has_milestones: batch.has_milestones,
                milestones: batch.milestones || []
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Module batch added. Remember to save!', 'success');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function removeAllocation(allocationId) {
            const allocationKey = String(allocationId);
            workingState.moduleAllocations = workingState.moduleAllocations.filter(a => {
                const key = a.id ?? a.module_id;
                return String(key) !== allocationKey;
            });
            showToast('Module removed. Remember to save!', 'info');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function addManualModuleAllocation(data) {
            // Add manual module allocation directly to working state
            const milestones = data.milestones || [];
            const allocation = {
                module_id: data.is_manual ? ('manual_' + Date.now()) : data.module_id,
                wattage: parseInt(data.wattage),
                quantity: parseInt(data.quantity),
                pallets: data.pallets,
                vendor_name: data.vendor_name,
                manufacturer_name: data.manufacturer_name,
                manufacturer_address: data.manufacturer_address || '',
                modules_per_pallet: data.modules_per_pallet,
                pallets_per_truck: data.pallets_per_truck,
                cost_per_watt: data.cost_per_watt,
                contract_value: data.contract_value,
                has_milestones: milestones.length > 0,
                milestones: milestones,
                is_manual: true
            };

            workingState.moduleAllocations.push(allocation);
            showToast('Manual module entry added. Remember to save!', 'success');

            // Mark as unsaved and update UI
            markAsUnsaved();
            renderModuleAllocations();
            updateBadges();
        }

        function renderModuleAllocations() {
            const container = document.getElementById('moduleAllocationsList');
            if (!container) return;

            if (workingState.moduleAllocations.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <p>No modules allocated to this projection yet.</p>
                    </div>
                `;
                updateModuleSummary();
                if (typeof updateAvailableBatchStates === 'function') {
                    updateAvailableBatchStates();
                }
                return;
            }

            const triggerLabels = {
                po_execution: 'PO Execution',
                shipping: 'Shipping',
                customs_cleared: 'Customs Clearance',
                project_delivery: 'Project Delivery'
            };

            const formatContractValue = (value) => {
                const amount = Number(value || 0);
                return amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            const formatPoDate = (value) => {
                if (!value) return '';
                // Parse date string without timezone conversion (YYYY-MM-DD format)
                const parts = value.split('-');
                if (parts.length !== 3) return value;
                const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
                if (Number.isNaN(dateObj.getTime())) {
                    return value;
                }
                return dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };

            let html = '';
            workingState.moduleAllocations.forEach((alloc, index) => {
                const allocationKey = alloc.id ?? alloc.module_id ?? `allocation_${index}`;
                const allocationKeyLiteral = JSON.stringify(String(allocationKey));
                const allocationKeyAttr = escapeHtml(String(allocationKey));
                const pallets = alloc.pallets || Math.ceil(alloc.quantity / (alloc.modules_per_pallet || 30));
                const contractValue = alloc.contract_value || 0;
                const vendorName = alloc.vendor_name || alloc.manufacturer_name || 'Unknown Vendor';
                const poDate = alloc.po_execution_date || '';
                const isCollapsed = poDate ? 'collapsed' : '';
                const formattedPoDate = formatPoDate(poDate);
                const modulesPerPallet = alloc.modules_per_pallet || '-';
                const palletsPerTruck = alloc.pallets_per_truck || '-';
                const milestones = alloc.milestones || [];

                const headerStats = [
                    `
                        <div class="stat-item">
                            <span class="stat-value">${(alloc.wattage || 0).toLocaleString()}W</span>
                        </div>
                    `,
                    `
                        <div class="stat-item">
                            <span class="stat-value">${(alloc.quantity || 0).toLocaleString()}</span>
                            <span class="stat-label">modules</span>
                        </div>
                    `,
                    `
                        <div class="stat-item">
                            <span class="stat-value">${pallets.toLocaleString()}</span>
                            <span class="stat-label">pallets</span>
                        </div>
                    `
                ];

                if (alloc.pallets_per_truck) {
                    headerStats.push(`
                        <div class="stat-item">
                            <span class="stat-value">${alloc.pallets_per_truck.toLocaleString()}</span>
                            <span class="stat-label">pallets/truck</span>
                        </div>
                    `);
                }

                headerStats.push(`
                    <div class="stat-item highlight">
                        <span class="stat-value">$${formatContractValue(contractValue)}</span>
                    </div>
                `);

                let milestonesHtml = '';
                if (milestones.length > 0) {
                    const milestonesItems = milestones.map(milestone => {
                        const triggerEvent = milestone.trigger_event || milestone.trigger || '';
                        const label = triggerLabels[triggerEvent] || milestone.milestone_name || milestone.name || 'Milestone';
                        const percentage = parseFloat(milestone.percentage) || 0;
                        const amount = parseFloat(milestone.amount) || 0;
                        const amountLabel = percentage > 0
                            ? `${percentage.toFixed(1)}%`
                            : `$${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

                        return `
                            <div class="milestone-row">
                                <div class="milestone-info">
                                    <span class="milestone-name">${escapeHtml(label)}</span>
                                </div>
                                <div class="milestone-amount">${amountLabel}</div>
                            </div>
                        `;
                    }).join('');

                    milestonesHtml = `
                        <div class="milestones-list">
                            ${milestonesItems}
                        </div>
                    `;
                } else {
                    milestonesHtml = `
                        <div class="milestones-empty">
                            <p>No milestones configured for this batch.</p>
                            <small>Milestones are configured in the module batch settings.</small>
                        </div>
                    `;
                }

                const requiresPoExecution = milestones.some(milestone => {
                    const triggerEvent = milestone.trigger_event || milestone.trigger || '';
                    return triggerEvent === 'po_execution';
                });
                const poRequiredAttr = requiresPoExecution ? 'required data-po-required="true"' : '';

                const actionsHtml = canEdit
                    ? `
                        <div class="module-item-actions">
                            <button type="button" class="btn btn-sm btn-danger" onclick="removeModuleAllocation(${allocationKeyLiteral})">
                                Remove Batch
                            </button>
                        </div>
                    `
                    : '';

                html += `
                    <div class="module-item ${isCollapsed}" data-allocation-id="${allocationKeyAttr}">
                        <div class="module-item-header" onclick="toggleModuleItem(${allocationKeyLiteral})">
                            <div class="module-header-left">
                                <div class="module-vendor-name">${escapeHtml(vendorName)}</div>
                                ${alloc.manufacturer_address ? `
                                    <div class="module-manufacturer-location">${escapeHtml(alloc.manufacturer_address)}</div>
                                ` : ''}
                                ${poDate ? `
                                    <span class="po-badge-sm">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                        PO: ${formattedPoDate}
                                    </span>
                                ` : ''}
                            </div>
                            <div class="module-header-stats">
                                ${headerStats.join('')}
                            </div>
                            <div class="module-header-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                            </div>
                        </div>

                        <div class="module-item-body">
                            <div class="module-details-grid">
                                <div class="detail-card">
                                    <span class="detail-label">Wattage</span>
                                    <span class="detail-value">${(alloc.wattage || 0).toLocaleString()}W</span>
                                </div>
                                <div class="detail-card">
                                    <span class="detail-label">Total Modules</span>
                                    <span class="detail-value">${(alloc.quantity || 0).toLocaleString()}</span>
                                </div>
                                <div class="detail-card">
                                    <span class="detail-label">Total Pallets</span>
                                    <span class="detail-value">${pallets.toLocaleString()}</span>
                                </div>
                                <div class="detail-card">
                                    <span class="detail-label">Mods/Pallet</span>
                                    <span class="detail-value">${modulesPerPallet}</span>
                                </div>
                                <div class="detail-card">
                                    <span class="detail-label">Pallets/Truck</span>
                                    <span class="detail-value">${palletsPerTruck}</span>
                                </div>
                                <div class="detail-card highlight">
                                    <span class="detail-label">Contract Value</span>
                                    <span class="detail-value">$${formatContractValue(contractValue)}</span>
                                </div>
                            </div>

                            <div class="module-expanded-columns">
                                <div class="po-execution-section">
                                    <div class="section-header">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                        <span>PO Execution Date</span>
                                    </div>
                                    <div class="po-execution-input">
                                        <input type="text" class="form-input flatpickr-date po-date-input"
                                               data-allocation-id="${allocationKeyAttr}"
                                               value="${escapeHtml(poDate)}"
                                               placeholder="Select date when PO was executed"
                                               ${canEdit ? '' : 'disabled'}
                                               ${poRequiredAttr}>
                                    </div>
                                </div>

                                <div class="milestones-section">
                                    <div class="section-header">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                            <polyline points="22 4 12 14.01 9 11.01"/>
                                        </svg>
                                        <span>Milestone Payments</span>
                                    </div>
                                    ${milestonesHtml}
                                </div>
                            </div>

                            ${actionsHtml}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            updateModuleSummary();
            if (typeof updateAvailableBatchStates === 'function') {
                updateAvailableBatchStates();
            }
            if (typeof initializePoDatePickers === 'function') {
                initializePoDatePickers();
            }
        }

        function expandSection(sectionId) {
            const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
            const content = document.getElementById(`${sectionId}-content`);
            if (!header || !content) return;

            header.classList.remove('collapsed');
            content.classList.remove('collapsed');
            saveCollapsibleStates();
        }

        // ==================== LOGISTICS VIEW TOGGLE ====================
        function switchLogisticsView(view) {
            const buttons = document.querySelectorAll('.view-toggle-btn');
            const views = document.querySelectorAll('.logistics-view');

            buttons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.view === view);
            });

            views.forEach(v => {
                v.classList.toggle('active', v.id === `logistics-${view}-view`);
            });

            // Trigger map resize when switching to map view
            if (view === 'map' && map) {
                setTimeout(() => {
                    google.maps.event.trigger(map, 'resize');
                    updateMapFromState();
                }, 150);
            }
        }

        // ==================== STEPPER NAVIGATION ====================
        function getStepCompletionState() {
            const hasModules = Array.isArray(workingState.moduleAllocations) && workingState.moduleAllocations.length > 0;

            // Logistics is "done" when all inventory is delivered to the destination
            const palletsPerTruck = projectInfo.palletsPerTruck || 20;
            const totalPallets = getTotalPallets();
            const destinationStop = (workingState.stops || []).find(s => s.stop_type === 'destination');

            const hasDeliveredInventory = (workingState.legs || []).some(leg => {
                if (!destinationStop || leg.to_stop_id != destinationStop.id) {
                    return false;
                }
                const trucksRequired = parseInt(leg.trucks_required, 10) || 0;
                const hasCost = parseFloat(leg.freight_cost_per_truck) > 0
                    || parseFloat(leg.accessorial_cost_per_truck) > 0
                    || parseFloat(leg.total_freight_cost) > 0;
                const hasSchedule = leg.start_date || leg.end_date || parseFloat(leg.delivery_rate) > 0;
                const hasMode = leg.transport_mode && leg.transport_mode !== 'truck';
                const hasExplicitTrucks = trucksRequired > 0 && trucksRequired !== getTotalTrucks();
                const isConfigured = leg.is_configured || hasCost || hasSchedule || hasMode || hasExplicitTrucks;
                if (!isConfigured) {
                    return false;
                }
                const deliveredPallets = trucksRequired * palletsPerTruck;
                return deliveredPallets > 0;
            });

            let deliveredPalletsTotal = 0;
            if (destinationStop) {
                (workingState.legs || []).forEach(leg => {
                    if (leg.to_stop_id != destinationStop.id) return;
                    const trucksRequired = parseInt(leg.trucks_required, 10) || 0;
                    const hasCost = parseFloat(leg.freight_cost_per_truck) > 0
                        || parseFloat(leg.accessorial_cost_per_truck) > 0
                        || parseFloat(leg.total_freight_cost) > 0;
                    const hasSchedule = leg.start_date || leg.end_date || parseFloat(leg.delivery_rate) > 0;
                    const hasMode = leg.transport_mode && leg.transport_mode !== 'truck';
                    const hasExplicitTrucks = trucksRequired > 0 && trucksRequired !== getTotalTrucks();
                    const isConfigured = leg.is_configured || hasCost || hasSchedule || hasMode || hasExplicitTrucks;
                    if (!isConfigured) return;
                    deliveredPalletsTotal += trucksRequired * palletsPerTruck;
                });
            }

            const hasLogistics = totalPallets > 0 && hasDeliveredInventory && deliveredPalletsTotal >= totalPallets;

            const hasTimelineDates = (workingState.legs || []).some(leg => leg.start_date || leg.end_date)
                || (workingState.stops || []).some(stop => stop.estimated_arrival_date || stop.estimated_departure_date);

            const hasTimelineCosts = (workingState.legs || []).some(leg => parseFloat(leg.total_freight_cost) > 0)
                || (workingState.stops || []).some(stop => (stop.fees || []).some(fee => parseFloat(fee.estimated_cost) > 0))
                || (typeof collectMilestoneEvents === 'function' && collectMilestoneEvents().some(event => parseFloat(event.amount) > 0));

            // Costs step is only complete when logistics is done (all inventory at project site) AND has cost/date data
            const hasTimeline = hasLogistics && (hasTimelineDates || hasTimelineCosts);

            return {
                'modules-costs': hasModules,
                'logistics-plan': hasLogistics,
                'timeline': hasTimeline
            };
        }

        function deriveProjectionStatus(completion) {
            if (workingState.status === 'archived') {
                return 'archived';
            }

            const allComplete = stepperSections.every(section => completion[section]);
            return allComplete ? 'active' : 'draft';
        }

        function getStatusDescriptor(status) {
            const normalized = (status || 'draft').toLowerCase();
            const map = {
                draft: { label: 'Draft', className: 'status-draft' },
                active: { label: 'Completed', className: 'status-active' },
                completed: { label: 'Completed', className: 'status-completed' },
                archived: { label: 'Archived', className: 'status-archived' }
            };
            return map[normalized] || map.draft;
        }

        function updateProjectionStatusDisplay(status, isPrimary) {
            const statusBadge = document.getElementById('projectionStatusBadge');
            if (statusBadge) {
                const descriptor = getStatusDescriptor(status);
                statusBadge.textContent = descriptor.label;
                statusBadge.dataset.status = status;
                statusBadge.className = `status-badge ${descriptor.className}`;
            }

            const primaryBadge = document.getElementById('projectionPrimaryBadge');
            if (primaryBadge) {
                primaryBadge.style.display = isPrimary ? 'inline-flex' : 'none';
            }
        }

        function updateStepperState(activeSection = null) {
            if (typeof syncPlanState === 'function') {
                syncPlanState();
            }
            const completion = getStepCompletionState();
            const derivedStatus = deriveProjectionStatus(completion);
            const normalizedStatus = (workingState.status || 'draft').toLowerCase();
            const hasExplicitStatus = ['active', 'archived', 'completed'].includes(normalizedStatus);
            const statusToDisplay = hasExplicitStatus ? normalizedStatus : derivedStatus;

            if (normalizedStatus === 'draft' && derivedStatus === 'active') {
                workingState.status = 'active';
            }

            updateProjectionStatusDisplay(statusToDisplay, workingState.isPrimary);

            const steps = document.querySelectorAll('.stepper-step');
            const connectors = document.querySelectorAll('.stepper-connector');

            // Determine which section is currently expanded (if any)
            let currentActive = activeSection;
            if (currentActive === null) {
                // Check if any section is expanded
                currentActive = getExpandedSection();
            }

            steps.forEach(step => {
                const stepSection = step.dataset.step;
                const isActive = currentActive && stepSection === currentActive;
                step.classList.toggle('active', isActive);
                step.classList.toggle('completed', !isActive && completion[stepSection]);
            });

            connectors.forEach((conn, index) => {
                conn.classList.toggle('completed', completion[stepperSections[index]]);
            });
        }

        // Helper to find which section is currently expanded
        function getExpandedSection() {
            for (const sectionId of stepperSections) {
                const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
                if (header && !header.classList.contains('collapsed')) {
                    return sectionId;
                }
            }
            return null; // All collapsed
        }

        function navigateToStep(sectionId) {
            // Collapse all other sections first
            stepperSections.forEach(otherId => {
                if (otherId !== sectionId) {
                    const otherHeader = document.querySelector(`[data-section="${otherId}"] .collapsible-header`);
                    const otherContent = document.getElementById(`${otherId}-content`);
                    if (otherHeader && otherContent) {
                        otherHeader.classList.add('collapsed');
                        otherContent.classList.add('collapsed');
                    }
                }
            });

            // Expand the target section
            expandSection(sectionId);

            updateStepperState(sectionId);

            // Scroll to the section
            const section = document.querySelector(`[data-section="${sectionId}"]`);
            if (section) {
                setTimeout(() => {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }

            // Save state
            saveCollapsibleStates();
        }

        // Update stepper state based on scroll position
        function updateStepperOnScroll() {
            // Only update based on scroll if a section is expanded
            const expandedSection = getExpandedSection();
            if (!expandedSection) {
                // All sections collapsed - don't change stepper state on scroll
                return;
            }

            let activeSection = null;

            stepperSections.forEach(sectionId => {
                const section = document.querySelector(`[data-section="${sectionId}"]`);
                const header = document.querySelector(`[data-section="${sectionId}"] .collapsible-header`);
                // Only consider expanded sections
                if (section && header && !header.classList.contains('collapsed')) {
                    const rect = section.getBoundingClientRect();
                    if (rect.top < window.innerHeight / 2) {
                        activeSection = sectionId;
                    }
                }
            });

            if (activeSection) {
                updateStepperState(activeSection);
            }
        }

        // Throttled scroll listener for stepper
        let scrollTimeout;
        window.addEventListener('scroll', function() {
            if (scrollTimeout) return;
            scrollTimeout = setTimeout(() => {
                updateStepperOnScroll();
                scrollTimeout = null;
            }, 100);
        });

        function toggleAllocationExpand(index) {
            const item = document.querySelector(`.module-allocation-item[data-allocation-index="${index}"]`);
            if (!item) return;
            const details = item.querySelector('.allocation-details');
            const toggle = item.querySelector('.allocation-toggle');
            if (details.style.display === 'none') {
                details.style.display = 'block';
                toggle.style.transform = 'rotate(180deg)';
            } else {
                details.style.display = 'none';
                toggle.style.transform = 'rotate(0deg)';
            }
        }

        function updateModuleSummary() {
            const totalModules = workingState.moduleAllocations.reduce((sum, a) => sum + (a.quantity || 0), 0);
            const totalValue = workingState.moduleAllocations.reduce((sum, a) => sum + (a.contract_value || 0), 0);

            const modulesEl = document.getElementById('totalModulesCount');
            const valueEl = document.getElementById('totalContractValue');

            if (modulesEl) modulesEl.textContent = totalModules.toLocaleString();
            if (valueEl) valueEl.textContent = '$' + totalValue.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

            updateProjectAllocationTracker();
            updateStepperState();
        }

        // Update project allocation tracker (MW allocated vs committed)
        function updateProjectAllocationTracker() {
            const projectSizeMW = parseFloat(document.getElementById('projectSizeMWDisplay')?.textContent) || 0;

            // Calculate allocated MW from module allocations
            let allocatedMW = 0;
            workingState.moduleAllocations.forEach(alloc => {
                const watts = (alloc.wattage || 0) * (alloc.quantity || 0);
                allocatedMW += watts / 1000000; // Convert to MW
            });

            // Update display
            const allocatedDisplay = document.getElementById('allocatedMWDisplay');
            const barFill = document.getElementById('allocationBarFill');
            const statusEl = document.getElementById('allocationStatus');
            const allocatedStat = allocatedDisplay?.closest('.allocation-stat');

            const isComplete = allocatedMW >= projectSizeMW * 0.999 && allocatedMW <= projectSizeMW * 1.001;
            const isOver = allocatedMW > projectSizeMW * 1.001;

            if (allocatedDisplay) {
                allocatedDisplay.textContent = allocatedMW.toFixed(2);
            }

            // Add complete class to stat for green color
            if (allocatedStat) {
                allocatedStat.classList.toggle('complete', isComplete);
            }

            if (barFill && projectSizeMW > 0) {
                const percentage = Math.min((allocatedMW / projectSizeMW) * 100, 100);
                barFill.style.width = percentage + '%';

                // Update bar color based on status
                barFill.classList.remove('complete', 'over');
                if (isComplete) {
                    barFill.classList.add('complete');
                } else if (isOver) {
                    barFill.classList.add('over');
                }
            }

            if (statusEl) {
                let statusClass = 'status-incomplete';
                let statusText = 'Incomplete';

                if (isComplete) {
                    statusClass = 'status-complete';
                    statusText = '100% Allocated';
                } else if (isOver) {
                    statusClass = 'status-over';
                    statusText = 'Over-allocated';
                } else if (allocatedMW > 0) {
                    const pct = ((allocatedMW / projectSizeMW) * 100).toFixed(0);
                    statusText = pct + '% Allocated';
                }

                statusEl.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
            }
        }

        // Save and navigate to logistics section
        function saveAndContinueToLogistics() {
            if (workingState.moduleAllocations.length === 0) {
                showToast('Please add at least one module batch before continuing.', 'warning');
                return;
            }

            if (hasUnsavedChanges) {
                // Save first, then navigate
                // Since saveProjection does a page reload, we need to store the target section
                sessionStorage.setItem('navigateToSection', 'logistics-plan');
                saveProjection();
            } else {
                // No unsaved changes, just navigate
                navigateToStep('logistics-plan');
                const logisticsSection = document.querySelector('[data-section="logistics-plan"]');
                if (logisticsSection) {
                    setTimeout(() => {
                        logisticsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 100);
                }
            }
        }

        // Check for stored navigation target on page load
        function checkStoredNavigation() {
            const targetSection = sessionStorage.getItem('navigateToSection');
            if (targetSection) {
                sessionStorage.removeItem('navigateToSection');
                setTimeout(() => {
                    navigateToStep(targetSection);
                    const section = document.querySelector(`[data-section="${targetSection}"]`);
                    if (section) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    hideLoading();
                }, 300);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function validateModulePoDates() {
            const allocations = workingState.moduleAllocations || [];
            const missingPoAllocations = allocations.filter(alloc => {
                const milestones = alloc.milestones || [];
                const requiresPo = milestones.some(milestone => {
                    const triggerEvent = milestone.trigger_event || milestone.trigger || '';
                    return triggerEvent === 'po_execution';
                });
                return requiresPo && !(alloc.po_execution_date || '').trim();
            });

            if (missingPoAllocations.length === 0) {
                return true;
            }

            showToast('Please enter the PO Execution date for module batches with PO milestones.', 'warning');

            const firstAllocation = missingPoAllocations[0];
            const allocationId = firstAllocation.id ?? firstAllocation.module_id;
            if (allocationId) {
                expandSection('modules-costs');
                const moduleItem = document.querySelector(`.module-item[data-allocation-id="${allocationId}"]`);
                if (moduleItem) {
                    moduleItem.classList.remove('collapsed');
                    moduleItem.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    const input = moduleItem.querySelector('.po-date-input');
                    if (input) {
                        setTimeout(() => input.focus(), 200);
                    }
                }
            }

            return false;
        }

        // ==================== STOP MANAGEMENT ====================
        function openAddStopModal(afterStopId = null) {
            addJourneyStop(afterStopId, { openEditor: true });
        }

        function openStopEditorModal(stopId) {
            openNodeEditor(stopId);
        }

        function closeStopEditorModal() {
            const modal = document.getElementById('stopEditorModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }

        function addFeeRow(feeData = null) {
            const container = document.getElementById('feesContainer');
            const rowId = 'fee_' + Date.now();

            const html = `
                <div class="fee-row" id="${rowId}" style="display: grid; grid-template-columns: 1fr 100px 120px 120px 40px; gap: 10px; margin-bottom: 12px; align-items: end;">
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Fee Name</label>
                        <input type="text" class="form-input fee-name" value="${feeData?.fee_name || ''}" placeholder="Fee name" style="padding: 10px;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Amount</label>
                        <input type="number" class="form-input fee-rate" value="${feeData?.rate || ''}" placeholder="$0" step="0.01" style="padding: 10px;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">Billing Unit</label>
                        <select class="form-input fee-unit" style="padding: 10px;">
                            <option value="per_pallet" ${feeData?.rate_unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                            <option value="per_truck" ${feeData?.rate_unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                            <option value="per_sqft" ${feeData?.rate_unit === 'per_sqft' ? 'selected' : ''}>Per SQFT</option>
                            <option value="flat" ${feeData?.rate_unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8em;">When Charged</label>
                        <select class="form-input fee-type" style="padding: 10px;">
                            <option value="receiving" ${feeData?.fee_type === 'receiving' ? 'selected' : ''}>On Entry</option>
                            <option value="outbound" ${feeData?.fee_type === 'outbound' ? 'selected' : ''}>On Exit</option>
                            <option value="storage" ${feeData?.fee_type === 'storage' ? 'selected' : ''}>Monthly</option>
                            <option value="one_time" ${feeData?.fee_type === 'one_time' ? 'selected' : ''}>One Time</option>
                        </select>
                    </div>
                    <button type="button" onclick="document.getElementById('${rowId}').remove()" style="padding: 10px; background: #f8d7da; border: none; border-radius: 8px; cursor: pointer; color: #dc3545;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            `;

            container.insertAdjacentHTML('beforeend', html);
        }

        function saveStop() {
            const stopId = document.getElementById('editStopId').value;
            const stopData = {
                stop_type: document.getElementById('stopType').value,
                location_name: document.getElementById('stopName').value,
                location_address: document.getElementById('stopAddress').value,
                estimated_arrival_date: document.getElementById('stopArrival').value || null,
                estimated_departure_date: document.getElementById('stopDeparture').value || null,
                is_customs_clearance: document.getElementById('stopIsCustoms').checked ? 1 : 0,
                notes: document.getElementById('stopNotes').value,
                fees: []
            };

            // Collect fees
            document.querySelectorAll('.fee-row').forEach(row => {
                const feeType = row.querySelector('.fee-type').value;
                const feeName = row.querySelector('.fee-name').value;
                const rate = parseFloat(row.querySelector('.fee-rate').value) || 0;
                const rateUnit = row.querySelector('.fee-unit').value;

                if (feeName && rate > 0) {
                    stopData.fees.push({
                        fee_type: feeType,
                        fee_name: feeName,
                        rate: rate,
                        rate_unit: rateUnit,
                        estimated_cost: rate * (getTotalPallets() || 1) // Simple estimate
                    });
                }
            });

            if (!stopData.location_name) {
                showToast('Please enter a location name', 'error');
                return;
            }

            if (stopId) {
                // Update existing stop
                const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
                if (stopIndex >= 0) {
                    stopData.id = stopId;
                    workingState.stops[stopIndex] = { ...workingState.stops[stopIndex], ...stopData };
                }
            } else {
                // Add new stop
                stopData.id = 'new_' + Date.now();
                // Insert before destination
                const destIndex = workingState.stops.findIndex(s => s.stop_type === 'destination');
                if (destIndex >= 0) {
                    workingState.stops.splice(destIndex, 0, stopData);
                } else {
                    workingState.stops.push(stopData);
                }
            }

            closeStopEditorModal();
            showToast('Stop updated. Remember to save the projection!', 'success');
            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();
        }

        function removeStop(stopId) {
            workingState.stops = workingState.stops.filter(s => s.id != stopId);
            // Also remove associated legs
            workingState.legs = workingState.legs.filter(l => l.from_stop_id != stopId && l.to_stop_id != stopId);
            showToast('Stop removed. Remember to save the projection!', 'info');
            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();
        }

        // Alias for removeStop
        function deleteStop(stopId) {
            if (confirm('Are you sure you want to remove this stop? This will also remove any associated routes.')) {
                removeStop(stopId);
            }
        }

        // ==================== LEG MANAGEMENT ====================
        function openLegEditorModal(legId, fromStopId, toStopId) {
            let leg = legId ? workingState.legs.find(l => l.id == legId) : null;

            // If no existing leg but we have from/to stops, find it or create reference
            if (!leg && fromStopId && toStopId) {
                leg = workingState.legs.find(l => l.from_stop_id == fromStopId && l.to_stop_id == toStopId);
            }

            // Set currentEditingLeg for saveLegEditor compatibility
            currentEditingLeg = leg;

            const effectiveFromId = leg?.from_stop_id || fromStopId;
            const effectiveToId = leg?.to_stop_id || toStopId;
            const fromStop = effectiveFromId ? workingState.stops.find(s => s.id == effectiveFromId) : null;
            const toStop = effectiveToId ? workingState.stops.find(s => s.id == effectiveToId) : null;

            // Title
            const fromName = fromStop?.location_name || 'Origin';
            const toName = toStop?.location_name || 'Destination';
            document.getElementById('legModalTitle').textContent = `${fromName} \u2192 ${toName}`;
            document.getElementById('legModalSubtitle').textContent = 'Set transport details for this route';

            // Populate inventory card
            const totalModules = workingState.moduleAllocations.reduce((sum, a) => sum + (a.quantity || 0), 0);
            const avgModsPerPallet = workingState.moduleAllocations[0]?.modules_per_pallet || 30;
            const avgPalletsPerTruck = workingState.moduleAllocations[0]?.pallets_per_truck || 20;
            const totalPallets = Math.ceil(totalModules / avgModsPerPallet);
            const totalTrucks = Math.ceil(totalPallets / avgPalletsPerTruck);

            const avTrucks = document.getElementById('legAvailableTrucks');
            const avPallets = document.getElementById('legAvailablePallets');
            const avModules = document.getElementById('legAvailableModules');
            if (avTrucks) avTrucks.textContent = totalTrucks;
            if (avPallets) avPallets.textContent = totalPallets;
            if (avModules) avModules.textContent = totalModules;

            // Populate form
            document.getElementById('editLegId').value = leg?.id || '';
            document.getElementById('legFromStopId').value = effectiveFromId || '';
            document.getElementById('legToStopId').value = effectiveToId || '';
            document.getElementById('legTransportMode').value = leg?.transport_mode || 'truck';
            document.getElementById('legStartDate').value = leg?.start_date || '';
            document.getElementById('legEndDate').value = leg?.end_date || '';
            document.getElementById('legDeliveryRate').value = leg?.delivery_rate || '';
            document.getElementById('legRateUnit').value = leg?.delivery_rate_unit || 'per_week';
            document.getElementById('legTrucksRequired').value = leg?.trucks_required || '';
            document.getElementById('legFreightCost').value = leg?.freight_cost_per_truck || '';
            document.getElementById('legAccessorialCost').value = leg?.accessorial_cost_per_truck || '';
            document.getElementById('legTriggersMilestone').value = leg?.triggers_milestone || '';
            document.getElementById('legNotes').value = leg?.notes || '';

            // Set active transport mode button
            selectLegTransportMode(leg?.transport_mode || 'truck');

            calculateLegTotal();

            document.getElementById('legEditorModal').classList.add('active');
            initializeDatePickers();
        }

        function selectLegTransportMode(mode) {
            const selector = document.getElementById('legTransportModeSelector');
            const hiddenSelect = document.getElementById('legTransportMode');
            if (selector) {
                selector.querySelectorAll('.leg-mode-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.mode === mode);
                });
            }
            if (hiddenSelect) hiddenSelect.value = mode;
        }

        function autoCalcEndDate() {
            const startDate = document.getElementById('legStartDate')?.value;
            const rate = parseInt(document.getElementById('legDeliveryRate')?.value, 10);
            const unit = document.getElementById('legRateUnit')?.value;
            const trucks = parseInt(document.getElementById('legTrucksRequired')?.value, 10) || getTotalTrucks();

            if (!startDate || !rate || rate <= 0 || !trucks) {
                document.getElementById('legEndDate').value = '';
                return;
            }

            const endDate = calculateEndDate(startDate, rate, unit, trucks);
            if (endDate) {
                document.getElementById('legEndDate').value = endDate;
            }
        }

        // Alias for closeLegEditor (used by some code paths)
        function closeLegEditorModal() {
            closeLegEditor();
        }

        function calculateLegTotal() {
            const trucks = parseInt(document.getElementById('legTrucksRequired').value) || getTotalTrucks();
            const freightCost = parseFloat(document.getElementById('legFreightCost').value) || 0;
            const accessorialCost = parseFloat(document.getElementById('legAccessorialCost').value) || 0;
            const total = trucks * (freightCost + accessorialCost);

            document.getElementById('legTotalDisplay').textContent = '$' + total.toLocaleString('en-US', { minimumFractionDigits: 2 });
        }

        // Alias for saveLegEditor (used by some code paths)
        function saveLeg() {
            saveLegEditor();
        }

        // ==================== LOGISTICS PLAN (INLINE) ====================
        function ensureStops() {
            const stops = workingState.stops || [];
            let origin = stops.find(stop => stop.stop_type === 'origin');
            let destination = stops.find(stop => stop.stop_type === 'destination');

            const manufacturer = workingState.moduleAllocations[0]?.manufacturer_name || workingState.moduleAllocations[0]?.vendor_name || 'Manufacturer';
            const manufacturerAddress = workingState.moduleAllocations[0]?.manufacturer_address || '';

            if (!origin) {
                origin = {
                    id: `origin_${Date.now()}`,
                    stop_type: 'origin',
                    location_name: manufacturer,
                    location_address: manufacturerAddress,
                    latitude: null,
                    longitude: null,
                    fees: []
                };
            } else {
                // Sync origin with latest allocation data if address is missing or changed
                if (manufacturerAddress && !origin.location_address) {
                    origin.location_address = manufacturerAddress;
                    origin.latitude = null;
                    origin.longitude = null;
                }
                if (manufacturer && (!origin.location_name || origin.location_name === 'Manufacturer')) {
                    origin.location_name = manufacturer;
                }
            }

            // Geocode origin address if available and coordinates are missing
            if (origin.location_address && !origin.latitude) {
                geocodeAddress(origin.location_address, (coords) => {
                    if (coords) {
                        origin.latitude = coords.lat;
                        origin.longitude = coords.lng;
                        updateMapFromState();
                    }
                });
            }

            if (!destination) {
                destination = {
                    id: `destination_${Date.now()}`,
                    stop_type: 'destination',
                    location_name: projectInfo.name || 'Project Site',
                    location_address: projectInfo.address || '',
                    latitude: null,
                    longitude: null,
                    fees: []
                };
            }

            // Geocode destination address if not already geocoded
            if (destination.location_address && !destination.latitude) {
                geocodeAddress(destination.location_address, (coords) => {
                    if (coords) {
                        destination.latitude = coords.lat;
                        destination.longitude = coords.lng;
                        updateMapFromState();
                    }
                });
            }

            const intermediates = stops.filter(stop => stop.stop_type !== 'origin' && stop.stop_type !== 'destination');
            const orderedStops = [];
            if (origin) orderedStops.push(origin);
            orderedStops.push(...intermediates);
            if (destination) orderedStops.push(destination);

            orderedStops.forEach((stop, index) => {
                if (!stop.id) {
                    stop.id = `stop_${Date.now()}_${index}`;
                }
                if (!Array.isArray(stop.fees)) {
                    stop.fees = [];
                }
            });

            workingState.stops = orderedStops;
        }

        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function getMilestoneForStop(stop) {
            if (!stop) return { value: '', label: 'Milestone' };
            if (stop.stop_type === 'destination') {
                return { value: 'project_delivery', label: 'Project Delivery' };
            }
            if (stop.is_customs_clearance || stop.stop_type === 'customs') {
                return { value: 'customs_cleared', label: 'Customs Cleared' };
            }
            return { value: 'shipping', label: 'Shipping' };
        }

        function getLegForStops(fromId, toId) {
            let leg = workingState.legs.find(l => l.from_stop_id == fromId && l.to_stop_id == toId);
            if (!leg) {
                leg = {
                    id: `leg_${Date.now()}_${Math.random().toString(16).slice(2)}`,
                    from_stop_id: fromId,
                    to_stop_id: toId,
                    transport_mode: 'truck',
                    delivery_rate_unit: 'per_week'
                };
                workingState.legs.push(leg);
            }
            return leg;
        }

        function calculateEndDate(startDate, deliveryRate, rateUnit, trucks) {
            if (!startDate || !deliveryRate || deliveryRate <= 0) return '';
            const rate = parseFloat(deliveryRate);
            const totalTrucks = parseInt(trucks, 10) || 1;
            let daysPerDelivery = 1;

            if (rateUnit === 'per_week') {
                daysPerDelivery = 7 / rate;
            } else if (rateUnit === 'per_month') {
                daysPerDelivery = 30 / rate;
            } else {
                daysPerDelivery = 1 / rate;
            }

            const totalDays = Math.ceil(totalTrucks * daysPerDelivery);
            const start = new Date(startDate);
            if (Number.isNaN(start.getTime())) return '';
            start.setDate(start.getDate() + totalDays);
            return start.toISOString().split('T')[0];
        }

        function calculateFeeEstimate(fee, stop) {
            const rate = parseFloat(fee.rate) || 0;
            if (!rate) return 0;
            const pallets = getTotalPallets() || 1;
            const modules = workingState.moduleAllocations.reduce((sum, alloc) => sum + (alloc.quantity || 0), 0) || 1;
            const trucks = getTotalTrucks();

            // Calculate base cost based on unit
            let baseCost = 0;
            switch (fee.rate_unit) {
                case 'per_module':
                    baseCost = rate * modules;
                    break;
                case 'per_truck':
                    baseCost = rate * trucks;
                    break;
                case 'per_sqft':
                    // Assume 13.33 sqft per pallet default
                    const sqftPerPallet = 13.33;
                    baseCost = rate * pallets * sqftPerPallet;
                    break;
                case 'flat':
                    baseCost = rate;
                    break;
                case 'per_pallet':
                default:
                    baseCost = rate * pallets;
                    break;
            }

            // For monthly (storage) fees, multiply by number of months in storage
            if (fee.fee_type === 'storage' && stop) {
                const stopIndex = workingState.stops.indexOf(stop);
                if (stopIndex === -1) return baseCost;

                const nextStop = workingState.stops[stopIndex + 1];

                if (stop.estimated_arrival_date && nextStop?.estimated_arrival_date) {
                    const entryDate = new Date(stop.estimated_arrival_date);
                    const exitDate = new Date(nextStop.estimated_arrival_date);
                    const daysInStorage = Math.ceil((exitDate - entryDate) / (1000 * 60 * 60 * 24));
                    const monthsInStorage = Math.max(1, Math.ceil(daysInStorage / 30));
                    return baseCost * monthsInStorage;
                }
            }

            return baseCost;
        }

        function syncPlanState() {
            ensureStops();

            const stops = workingState.stops || [];
            const legPairs = new Set();
            for (let i = 0; i < stops.length - 1; i++) {
                const fromStop = stops[i];
                const toStop = stops[i + 1];
                if (!fromStop || !toStop) continue;
                legPairs.add(`${fromStop.id}__${toStop.id}`);
                getLegForStops(fromStop.id, toStop.id);
            }

            workingState.legs = workingState.legs.filter(leg => legPairs.has(`${leg.from_stop_id}__${leg.to_stop_id}`));

            workingState.legs.forEach(leg => {
                const toStop = workingState.stops.find(stop => stop.id == leg.to_stop_id);
                const milestone = getMilestoneForStop(toStop);
                leg.triggers_milestone = milestone.value;

                const trucks = parseInt(leg.trucks_required, 10) || getTotalTrucks();
                leg.trucks_required = trucks;

                const endDate = calculateEndDate(leg.start_date, leg.delivery_rate, leg.delivery_rate_unit, trucks);
                if (endDate) {
                    leg.end_date = endDate;
                }

                const freight = parseFloat(leg.freight_cost_per_truck) || 0;
                const accessorial = parseFloat(leg.accessorial_cost_per_truck) || 0;
                leg.total_freight_cost = trucks * (freight + accessorial);

                if (toStop && leg.end_date) {
                    toStop.estimated_arrival_date = leg.end_date;
                }
            });

            workingState.stops.forEach(stop => {
                if (!Array.isArray(stop.fees)) {
                    stop.fees = [];
                }
                stop.fees = stop.fees.map(fee => ({
                    ...fee,
                    estimated_cost: calculateFeeEstimate(fee, stop)
                }));
            });
        }

        // ==================== FLOW CANVAS - JOURNEY PLAN REDESIGN ====================

        // State for modal editing and drag-to-connect
        let currentEditingNode = null;
        let currentEditingLeg = null;
        let currentAddStopParentId = null;
        let branchConfigData = { branches: [], parentStopId: null };

        // Drag-to-connect state
        let isDraggingConnection = false;
        let dragSourceStopId = null;
        let dragSourcePort = null;

        function renderJourneyPlan() {
            syncPlanState();
            cleanupAutocompleteInstances();

            // Render the new journey flow layout
            renderJourneyFlow();

            // Update badges and stats
            updateBadges();
            updateStepperState();
        }

        // ==================== NEW JOURNEY FLOW RENDERING ====================
        function renderJourneyFlow() {
            const layoutContainer = document.getElementById('journeyFlowLayout');
            const originContainer = document.getElementById('journeyOriginNodes');
            const stopsContainer = document.getElementById('journeyStopsScroll');
            const destinationContainer = document.getElementById('journeyDestinationNodes');
            const emptyState = document.getElementById('journeyEmptyState');
            const addStopBtn = document.getElementById('journeyAddStopBtn');

            if (!layoutContainer) {
                // Fall back to old flow canvas if new layout not present
                renderFlowCanvas();
                return;
            }

            const stops = workingState.stops || [];
            const allocations = workingState.moduleAllocations || [];
            const legs = workingState.legs || [];

            // Debug log
            console.log('renderJourneyFlow - allocations:', allocations.length, allocations);

            // Check if we should show empty state (no modules allocated)
            const hasModules = allocations && allocations.length > 0;

            if (!hasModules) {
                if (emptyState) emptyState.style.display = 'flex';
                if (layoutContainer) layoutContainer.style.opacity = '0.3';
                if (originContainer) originContainer.innerHTML = '<div class="journey-node-placeholder">No modules yet</div>';
                if (stopsContainer) stopsContainer.innerHTML = '';
                if (destinationContainer) destinationContainer.innerHTML = '<div class="journey-node-placeholder">Destination</div>';
                return;
            }

            // Hide empty state and show layout
            if (emptyState) emptyState.style.display = 'none';
            if (layoutContainer) layoutContainer.style.opacity = '1';

            // Get origin and destination stops
            const originStop = stops.find(s => s.stop_type === 'origin');
            const destinationStop = stops.find(s => s.stop_type === 'destination');
            const intermediateStops = stops.filter(s => !['origin', 'destination'].includes(s.stop_type));

            // Calculate total inventory at origin
            const totalModules = allocations.reduce((sum, a) => sum + (a.quantity || 0), 0);
            const avgModsPerPallet = allocations[0]?.modules_per_pallet || 30;
            const avgPalletsPerTruck = allocations[0]?.pallets_per_truck || 20;
            const totalPallets = Math.ceil(totalModules / avgModsPerPallet);
            const totalTrucks = Math.ceil(totalPallets / avgPalletsPerTruck);

            const defaultTrucks = getTotalTrucks();
            const getLegPallets = (leg) => {
                const trucksRequired = parseInt(leg.trucks_required, 10) || 0;
                const hasCost = parseFloat(leg.freight_cost_per_truck) > 0
                    || parseFloat(leg.accessorial_cost_per_truck) > 0
                    || parseFloat(leg.total_freight_cost) > 0;
                const hasSchedule = leg.start_date || leg.end_date || parseFloat(leg.delivery_rate) > 0;
                const hasMode = leg.transport_mode && leg.transport_mode !== 'truck';
                const hasExplicitTrucks = trucksRequired > 0 && trucksRequired !== defaultTrucks;
                const isConfigured = leg.is_configured || hasCost || hasSchedule || hasMode || hasExplicitTrucks;
                if (!isConfigured) {
                    return 0;
                }
                return trucksRequired * avgPalletsPerTruck;
            };

            const inboundPallets = {};
            const outboundPallets = {};

            legs.forEach(leg => {
                const pallets = getLegPallets(leg);
                if (!pallets) return;
                const fromId = leg.from_stop_id;
                const toId = leg.to_stop_id;
                outboundPallets[fromId] = (outboundPallets[fromId] || 0) + pallets;
                inboundPallets[toId] = (inboundPallets[toId] || 0) + pallets;
            });

            const originStopId = originStop?.id;
            const originOutbound = originStopId ? (outboundPallets[originStopId] || 0) : 0;
            const remainingOriginPallets = Math.max(totalPallets - originOutbound, 0);
            const remainingOriginRatio = totalPallets > 0 ? remainingOriginPallets / totalPallets : 0;

            // Render origin node(s) - each manufacturer is shown separately
            if (originContainer) {
                let originHtml = '';
                // Group allocations by manufacturer address
                const manufacturers = {};
                allocations.forEach(alloc => {
                    const key = alloc.manufacturer_address || 'Unknown Location';
                    if (!manufacturers[key]) {
                        manufacturers[key] = {
                            name: alloc.vendor_name || alloc.manufacturer_name || 'Manufacturer',
                            address: key,
                            modules: 0,
                            pallets: 0
                        };
                    }
                    const modsPerPallet = alloc.modules_per_pallet || 30;
                    manufacturers[key].modules += alloc.quantity || 0;
                    manufacturers[key].pallets += Math.ceil((alloc.quantity || 0) / modsPerPallet);
                });

                Object.values(manufacturers).forEach((mfg, idx) => {
                    const remainingPallets = Math.max(Math.round(mfg.pallets * remainingOriginRatio), 0);
                    const remainingModules = Math.max(Math.round(mfg.modules * remainingOriginRatio), 0);
                    const trucks = remainingPallets ? Math.ceil(remainingPallets / avgPalletsPerTruck) : 0;
                    originHtml += renderJourneyNode({
                        id: originStop?.id || `origin_${idx}`,
                        type: 'origin',
                        title: mfg.name,
                        address: mfg.address,
                        modules: remainingModules,
                        pallets: remainingPallets,
                        trucks: trucks,
                        showConnect: canEdit && remainingPallets > 0
                    });
                });

                originContainer.innerHTML = originHtml;
            }

            // Render intermediate stops
            if (stopsContainer) {
                let stopsHtml = '';
                if (intermediateStops.length === 0) {
                    stopsHtml = `
                        <div class="journey-stops-empty">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                            </svg>
                            <p>No intermediate stops configured.<br>Add warehouses or ports if needed.</p>
                        </div>
                    `;
                } else {
                    const visibleStops = intermediateStops.slice(0, 5);
                    const stopOrder = new Map(visibleStops.map((stop, index) => [stop.id, index]));
                    const adjacency = new Map();

                    legs.forEach(leg => {
                        if (!adjacency.has(leg.from_stop_id)) {
                            adjacency.set(leg.from_stop_id, []);
                        }
                        adjacency.get(leg.from_stop_id).push(leg.to_stop_id);
                    });

                    const depthByStop = {};
                    if (originStop?.id) {
                        const queue = [originStop.id];
                        depthByStop[originStop.id] = 0;

                        while (queue.length) {
                            const currentId = queue.shift();
                            const currentDepth = depthByStop[currentId] || 0;
                            const nextStops = adjacency.get(currentId) || [];

                            nextStops.forEach(nextId => {
                                const proposedDepth = currentDepth + 1;
                                if (depthByStop[nextId] === undefined || proposedDepth < depthByStop[nextId]) {
                                    depthByStop[nextId] = proposedDepth;
                                    queue.push(nextId);
                                }
                            });
                        }
                    }

                    let maxDepth = 0;
                    visibleStops.forEach(stop => {
                        if (depthByStop[stop.id] !== undefined) {
                            maxDepth = Math.max(maxDepth, depthByStop[stop.id]);
                        }
                    });

                    let fallbackDepth = maxDepth + 1;
                    const groupedStops = new Map();

                    visibleStops.forEach(stop => {
                        let depth = depthByStop[stop.id];
                        if (depth === undefined) {
                            depth = fallbackDepth;
                            fallbackDepth += 1;
                        }
                        if (!groupedStops.has(depth)) {
                            groupedStops.set(depth, []);
                        }
                        groupedStops.get(depth).push(stop);
                    });

                    const sortedDepths = Array.from(groupedStops.keys()).sort((a, b) => a - b);

                    sortedDepths.forEach(depth => {
                        const groupStops = groupedStops.get(depth) || [];
                        groupStops.sort((a, b) => stopOrder.get(a.id) - stopOrder.get(b.id));

                        stopsHtml += `<div class="journey-stop-group" data-depth="${depth}">`;
                        groupStops.forEach(stop => {
                            const incomingPallets = inboundPallets[stop.id] || 0;
                            const outgoingPallets = outboundPallets[stop.id] || 0;
                            const remainingPallets = Math.max(incomingPallets - outgoingPallets, 0);
                            const remainingModules = Math.max(Math.round(remainingPallets * avgModsPerPallet), 0);
                            const remainingTrucks = remainingPallets ? Math.ceil(remainingPallets / avgPalletsPerTruck) : 0;

                            stopsHtml += `<div class="journey-stop-card">`;
                            stopsHtml += renderJourneyNode({
                                id: stop.id,
                                type: 'stop',
                                stopType: stop.stop_type,
                                title: stop.location_name || getStopTypeLabel(stop.stop_type),
                                address: stop.location_address || '',
                                modules: remainingModules,
                                pallets: remainingPallets,
                                trucks: remainingTrucks,
                                fees: stop.fees || [],
                                showConnect: canEdit && remainingPallets > 0,
                                showReceive: canEdit,
                                showDelete: canEdit
                            });
                            stopsHtml += `</div>`;
                        });
                        stopsHtml += `</div>`;
                    });
                }
                stopsContainer.innerHTML = stopsHtml;
                stopsContainer.classList.toggle('is-empty', intermediateStops.length === 0);

                // Show/hide add stop button (max 5 stops)
                if (addStopBtn) {
                    addStopBtn.style.display = intermediateStops.length < 5 ? 'inline-flex' : 'none';
                }
            }

            // Render destination node
            if (destinationContainer) {
                const destinationId = destinationStop?.id;
                const deliveredPallets = destinationId ? (inboundPallets[destinationId] || 0) : 0;
                const deliveredModules = Math.max(Math.round(deliveredPallets * avgModsPerPallet), 0);

                destinationContainer.innerHTML = renderJourneyNode({
                    id: destinationStop?.id || 'destination',
                    type: 'destination',
                    title: projectInfo.name || 'Project Site',
                    address: projectInfo.address || '',
                    modules: deliveredModules,
                    pallets: deliveredPallets,
                    trucks: deliveredPallets ? Math.ceil(deliveredPallets / avgPalletsPerTruck) : 0,
                    showReceive: canEdit
                });
            }

            // Render connections after DOM update
            requestAnimationFrame(() => {
                renderJourneyConnections();
                bindJourneyFlowListeners();
            });
        }

        function renderJourneyNode(config) {
            const { id, type, stopType, title, address, modules, pallets, trucks, fees, showConnect, showReceive, showDelete } = config;

            const typeClass = type === 'origin' ? 'origin-node' :
                             type === 'destination' ? 'destination-node' : 'stop-node';

            const icon = type === 'origin' ?
                `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>` :
                type === 'destination' ?
                `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>` :
                stopType === 'port' ?
                `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>` :
                `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="3" width="15" height="13"/>
                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>`;

            // Fees display for stops
            let feesHtml = '';
            if (fees && fees.length > 0) {
                const totalFees = fees.reduce((sum, f) => sum + (parseFloat(f.estimated_cost) || 0), 0);
                feesHtml = `
                    <div class="journey-node-fees">
                        <span class="fee-badge">${fees.length} fee${fees.length > 1 ? 's' : ''} &bull; $${totalFees.toLocaleString()}</span>
                    </div>
                `;
            }

            // Actions
            let actionsHtml = '';
            if (canEdit && (type === 'stop' || type !== 'destination')) {
                actionsHtml = `
                    <div class="journey-node-actions">
                        <button type="button" class="journey-node-action-btn edit-btn" data-action="edit" data-stop-id="${id}">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Edit
                        </button>
                        ${showDelete ? `
                            <button type="button" class="journey-node-action-btn delete-btn" data-action="delete" data-stop-id="${id}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>
                        ` : ''}
                    </div>
                `;
            }

            return `
                <div class="journey-node ${typeClass}" data-stop-id="${id}" data-type="${type}">
                    ${showReceive ? `<div class="journey-node-receive" data-stop-id="${id}" data-action="receive"></div>` : ''}
                    <div class="journey-node-header">
                        <div class="journey-node-icon">${icon}</div>
                        <div class="journey-node-info">
                            <div class="journey-node-title">${escapeHtml(title)}</div>
                            ${address ? `<div class="journey-node-address">${escapeHtml(address)}</div>` : ''}
                        </div>
                    </div>
                    <div class="journey-node-inventory">
                        <div class="inventory-stat">
                            <span class="inventory-stat-value">${(modules || 0).toLocaleString()}</span>
                            <span class="inventory-stat-label">Modules</span>
                        </div>
                        <div class="inventory-stat">
                            <span class="inventory-stat-value">${(pallets || 0).toLocaleString()}</span>
                            <span class="inventory-stat-label">Pallets</span>
                        </div>
                        <div class="inventory-stat">
                            <span class="inventory-stat-value">${(trucks || 0).toLocaleString()}</span>
                            <span class="inventory-stat-label">Trucks</span>
                        </div>
                    </div>
                    ${feesHtml}
                    ${showConnect ? `
                        <button type="button" class="journey-node-connect" data-stop-id="${id}" data-action="connect" title="Create route from here">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14"/>
                                <path d="M12 5l7 7-7 7"/>
                            </svg>
                        </button>
                    ` : ''}
                    ${actionsHtml}
                </div>
            `;
        }

        function isLegConfigured(leg) {
            const defaultTrucks = getTotalTrucks();
            const trucksRequired = parseInt(leg.trucks_required, 10) || 0;
            const hasCost = parseFloat(leg.freight_cost_per_truck) > 0
                || parseFloat(leg.accessorial_cost_per_truck) > 0
                || parseFloat(leg.total_freight_cost) > 0;
            const hasSchedule = leg.start_date || leg.end_date || parseFloat(leg.delivery_rate) > 0;
            const hasMode = leg.transport_mode && leg.transport_mode !== 'truck';
            const hasExplicitTrucks = trucksRequired > 0 && trucksRequired !== defaultTrucks;
            return !!(leg.is_configured || hasCost || hasSchedule || hasMode || hasExplicitTrucks);
        }

        function renderJourneyConnections() {
            const svgContainer = document.getElementById('journeyConnectionsSvg');
            const layoutContainer = document.getElementById('journeyFlowLayout');
            if (!svgContainer || !layoutContainer) return;

            // Size the SVG to match the layout container
            const containerRect = layoutContainer.getBoundingClientRect();
            svgContainer.setAttribute('width', containerRect.width);
            svgContainer.setAttribute('height', containerRect.height);
            svgContainer.style.width = containerRect.width + 'px';
            svgContainer.style.height = containerRect.height + 'px';

            // Clear existing paths (keep defs)
            const defsContent = svgContainer.querySelector('defs')?.outerHTML || '';
            svgContainer.innerHTML = defsContent;

            // Remove any existing leg badges and popovers
            layoutContainer.querySelectorAll('.journey-leg-badge, .leg-popover').forEach(el => el.remove());

            const legs = workingState.legs || [];

            legs.forEach(leg => {
                const fromNode = layoutContainer.querySelector(`[data-stop-id="${leg.from_stop_id}"]`);
                const toNode = layoutContainer.querySelector(`[data-stop-id="${leg.to_stop_id}"]`);

                if (!fromNode || !toNode) return;

                const fromRect = fromNode.getBoundingClientRect();
                const toRect = toNode.getBoundingClientRect();

                // Calculate connection points
                const fromX = fromRect.right - containerRect.left;
                const fromY = fromRect.top + fromRect.height / 2 - containerRect.top;
                const toX = toRect.left - containerRect.left;
                const toY = toRect.top + toRect.height / 2 - containerRect.top;

                const configured = isLegConfigured(leg);

                // Create curved path
                const midX = (fromX + toX) / 2;
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', `M${fromX},${fromY} C${midX},${fromY} ${midX},${toY} ${toX},${toY}`);
                path.setAttribute('data-leg-id', leg.id);

                if (configured) {
                    path.setAttribute('class', 'journey-leg-line');
                    path.setAttribute('stroke', 'url(#legGradient)');
                    path.setAttribute('marker-end', 'url(#legArrow)');
                    path.style.cursor = 'pointer';
                    path.onclick = (e) => {
                        e.stopPropagation();
                        showLegPopover(leg, (fromX + toX) / 2, (fromY + toY) / 2);
                    };
                } else {
                    path.setAttribute('class', 'journey-leg-line-placeholder');
                }

                svgContainer.appendChild(path);

                // Only add badge for configured legs
                if (!configured) return;

                // Add transport mode badge on the line midpoint
                const badgeMidX = (fromX + toX) / 2;
                const badgeMidY = (fromY + toY) / 2;
                const modeIcons = {
                    truck: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                    rail: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/></svg>',
                    ocean: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2"/></svg>',
                    air: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2"/></svg>'
                };

                const badge = document.createElement('div');
                badge.className = 'journey-leg-badge';
                badge.dataset.legId = leg.id;
                badge.style.left = `${badgeMidX}px`;
                badge.style.top = `${badgeMidY}px`;
                badge.style.transform = 'translate(-50%, -50%)';
                badge.innerHTML = modeIcons[leg.transport_mode] || modeIcons.truck;
                badge.title = `${(leg.transport_mode || 'truck').charAt(0).toUpperCase() + (leg.transport_mode || 'truck').slice(1)} - Click for details`;
                badge.onclick = (e) => {
                    e.stopPropagation();
                    showLegPopover(leg, badgeMidX, badgeMidY);
                };

                layoutContainer.appendChild(badge);
            });
        }

        function formatDateForDisplay(dateStr) {
            if (!dateStr) return '--';
            const parts = dateStr.split('-');
            if (parts.length !== 3) return dateStr;
            const y = parseInt(parts[0], 10);
            const m = parseInt(parts[1], 10) - 1;
            const d = parseInt(parts[2], 10);
            const date = new Date(y, m, d);
            if (isNaN(date.getTime())) return dateStr;
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return `${months[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
        }

        function showLegPopover(leg, x, y) {
            const layoutContainer = document.getElementById('journeyFlowLayout');
            if (!layoutContainer) return;

            // Remove any existing popover
            layoutContainer.querySelectorAll('.leg-popover').forEach(el => el.remove());

            const fromStop = workingState.stops.find(s => s.id == leg.from_stop_id);
            const toStop = workingState.stops.find(s => s.id == leg.to_stop_id);
            const mode = (leg.transport_mode || 'truck').charAt(0).toUpperCase() + (leg.transport_mode || 'truck').slice(1);
            const trucks = leg.trucks_required || '--';
            const cost = leg.total_freight_cost ? '$' + Number(leg.total_freight_cost).toLocaleString('en-US', { minimumFractionDigits: 2 }) : '--';
            const startDate = leg.start_date ? formatDateForDisplay(leg.start_date) : '--';
            const endDate = leg.end_date ? formatDateForDisplay(leg.end_date) : '--';

            const popover = document.createElement('div');
            popover.className = 'leg-popover';
            popover.style.left = `${x}px`;
            popover.style.top = `${y + 20}px`;
            popover.innerHTML = `
                <div class="leg-popover-header">
                    <span class="leg-popover-title">${mode} Shipment</span>
                    <button type="button" class="leg-popover-close" onclick="this.closest('.leg-popover').remove()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="leg-popover-body">
                    <div class="leg-popover-row">
                        <span class="leg-popover-label">Trucks</span>
                        <span class="leg-popover-value">${trucks}</span>
                    </div>
                    <div class="leg-popover-row">
                        <span class="leg-popover-label">Schedule</span>
                        <span class="leg-popover-value">${startDate} - ${endDate}</span>
                    </div>
                    <div class="leg-popover-row">
                        <span class="leg-popover-label">Total Cost</span>
                        <span class="leg-popover-value" style="font-weight: 700; color: var(--primary);">${cost}</span>
                    </div>
                </div>
                <div class="leg-popover-actions">
                    <button type="button" class="leg-popover-btn edit" onclick="this.closest('.leg-popover').remove(); openLegEditorModal('${leg.id}');">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit
                    </button>
                    <button type="button" class="leg-popover-btn delete" onclick="this.closest('.leg-popover').remove(); deleteLeg('${leg.id}');">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        Delete
                    </button>
                </div>
            `;

            layoutContainer.appendChild(popover);

            // Close popover when clicking elsewhere
            const closeHandler = (e) => {
                if (!popover.contains(e.target)) {
                    popover.remove();
                    document.removeEventListener('click', closeHandler);
                }
            };
            setTimeout(() => document.addEventListener('click', closeHandler), 10);
        }

        function deleteLeg(legId) {
            if (!confirm('Delete this shipping route? Inventory will be returned to the previous location.')) return;

            const leg = workingState.legs.find(l => l.id == legId);
            if (leg) {
                // Reset to unconfigured state - syncPlanState will recreate anyway
                leg.is_configured = false;
                leg.trucks_required = null;
                leg.start_date = '';
                leg.end_date = '';
                leg.delivery_rate = 0;
                leg.delivery_rate_unit = 'per_week';
                leg.freight_cost_per_truck = 0;
                leg.accessorial_cost_per_truck = 0;
                leg.total_freight_cost = 0;
                leg.transport_mode = 'truck';
                leg.notes = '';
            }
            markAsUnsaved();
            renderJourneyFlow();
            updateMapFromState();
            updateTimelineChart();
            showToast('Shipping route deleted. Inventory returned to previous location.', 'info');
        }

        function bindJourneyFlowListeners() {
            const layoutContainer = document.getElementById('journeyFlowLayout');
            if (!layoutContainer) return;

            // Use a global state for connection mode (click-to-start, click-to-connect)
            if (!window.journeyConnectionState) {
                window.journeyConnectionState = {
                    isConnecting: false,
                    fromStopId: null,
                    fromButton: null
                };
            }
            const connectionState = window.journeyConnectionState;
            const dragLine = document.getElementById('journeyDragLine');

            // Helper to reset connection state
            function resetConnectionState() {
                connectionState.isConnecting = false;
                connectionState.fromStopId = null;
                if (connectionState.fromButton) {
                    connectionState.fromButton.classList.remove('connecting');
                }
                connectionState.fromButton = null;
                layoutContainer.classList.remove('dragging');
                layoutContainer.querySelectorAll('.journey-node-receive').forEach(port => {
                    port.classList.remove('can-receive');
                });
                if (dragLine) {
                    dragLine.style.display = 'none';
                }
            }

            // Connect button click - toggle connection mode
            layoutContainer.querySelectorAll('.journey-node-connect').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    // If already connecting from this button, cancel
                    if (connectionState.isConnecting && connectionState.fromButton === btn) {
                        resetConnectionState();
                        return;
                    }

                    // If connecting from another button, switch to this one
                    if (connectionState.isConnecting) {
                        resetConnectionState();
                    }

                    // Start connection mode
                    connectionState.isConnecting = true;
                    connectionState.fromStopId = btn.dataset.stopId;
                    connectionState.fromButton = btn;
                    layoutContainer.classList.add('dragging');
                    btn.classList.add('connecting');

                    // Highlight valid receive targets
                    layoutContainer.querySelectorAll('.journey-node-receive').forEach(port => {
                        const portStopId = port.dataset.stopId;
                        if (portStopId !== connectionState.fromStopId) {
                            port.classList.add('can-receive');
                        }
                    });

                    // Initialize drag line position
                    if (dragLine) {
                        const rect = btn.getBoundingClientRect();
                        const containerRect = layoutContainer.getBoundingClientRect();
                        const startX = rect.left + rect.width / 2 - containerRect.left;
                        const startY = rect.top + rect.height / 2 - containerRect.top;
                        dragLine.setAttribute('x1', startX);
                        dragLine.setAttribute('y1', startY);
                        dragLine.setAttribute('x2', startX);
                        dragLine.setAttribute('y2', startY);
                        dragLine.style.display = 'block';
                    }
                });
            });

            // Mouse move - update drag line (follows cursor without needing to hold mouse)
            layoutContainer.addEventListener('mousemove', (e) => {
                if (!connectionState.isConnecting || !dragLine) return;
                const containerRect = layoutContainer.getBoundingClientRect();
                dragLine.setAttribute('x2', e.clientX - containerRect.left);
                dragLine.setAttribute('y2', e.clientY - containerRect.top);
            });

            // Receive port click - complete connection
            layoutContainer.querySelectorAll('.journey-node-receive').forEach(port => {
                port.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    if (!connectionState.isConnecting) return;
                    if (!port.classList.contains('can-receive')) return;

                    const toStopId = port.dataset.stopId;
                    const fromStopId = connectionState.fromStopId;

                    // Reset state before creating leg
                    resetConnectionState();

                    // Create or edit leg
                    createOrEditLeg(fromStopId, toStopId);
                });
            });

            // Click anywhere else to cancel connection mode
            document.addEventListener('click', (e) => {
                if (!connectionState.isConnecting) return;

                // Don't cancel if clicking on connect or receive buttons (handled above)
                if (e.target.closest('.journey-node-connect') || e.target.closest('.journey-node-receive')) {
                    return;
                }

                resetConnectionState();
            });

            // Escape key to cancel
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && connectionState.isConnecting) {
                    resetConnectionState();
                }
            });

            // Node action buttons
            layoutContainer.querySelectorAll('.journey-node-action-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const action = btn.dataset.action;
                    const stopId = btn.dataset.stopId;

                    if (action === 'edit') {
                        openStopEditorModal(stopId);
                    } else if (action === 'delete') {
                        deleteStop(stopId);
                    }
                });
            });
        }

        function createOrEditLeg(fromStopId, toStopId) {
            // Check if leg already exists
            let existingLeg = (workingState.legs || []).find(l =>
                l.from_stop_id == fromStopId && l.to_stop_id == toStopId
            );

            if (existingLeg) {
                // Edit existing leg
                openLegEditorModal(existingLeg.id);
            } else {
                // Create new leg
                const newLeg = {
                    id: 'leg_' + Date.now(),
                    from_stop_id: fromStopId,
                    to_stop_id: toStopId,
                    transport_mode: 'truck',
                    trucks_required: 0,
                    freight_cost_per_truck: 0,
                    total_freight_cost: 0
                };

                if (!workingState.legs) workingState.legs = [];
                workingState.legs.push(newLeg);

                markAsUnsaved();
                renderJourneyFlow();

                // Open editor for the new leg
                setTimeout(() => openLegEditorModal(newLeg.id), 100);
            }
        }

        function renderFlowCanvas() {
            const container = document.getElementById('flowCanvas');
            const svgContainer = document.getElementById('flowConnectionsSvg');
            const emptyState = document.getElementById('flowCanvasEmpty');
            const addStopFab = document.getElementById('flowAddStopFab');

            if (!container) return;

            const stops = workingState.stops || [];

            if (stops.length < 2) {
                container.innerHTML = '';
                if (svgContainer) svgContainer.innerHTML = svgContainer.innerHTML.split('</defs>')[0] + '</defs>';
                if (emptyState) emptyState.style.display = 'flex';
                if (addStopFab) addStopFab.style.display = 'none';
                return;
            }

            if (emptyState) emptyState.style.display = 'none';
            if (addStopFab) addStopFab.style.display = 'flex';

            // Build the flow tree structure
            const flowTree = buildFlowTree(stops);

            // Render nodes
            let html = '';
            html += renderFlowLevel(flowTree, 0);

            container.innerHTML = html;

            // Render SVG connections after DOM is updated
            requestAnimationFrame(() => {
                renderFlowConnections();
                bindFlowCanvasListeners();
                initializeAddressAutocompletes();
            });
        }

        function buildFlowTree(stops) {
            // For now, support linear flow with potential branch points
            // Branch points are identified by stops with children array
            // The tree structure will support future branching

            const tree = {
                levels: [],
                connections: []
            };

            // Group stops into levels (for branching support)
            // Currently linear: each stop is its own level
            stops.forEach((stop, index) => {
                const isOrigin = stop.stop_type === 'origin';
                const isDestination = stop.stop_type === 'destination';

                // Check if this stop has branches (children)
                const branches = stop.branches || [];

                if (branches.length > 0) {
                    // This is a branch point
                    tree.levels.push({
                        type: 'branch-point',
                        stop: stop,
                        branches: branches
                    });
                } else {
                    tree.levels.push({
                        type: 'single',
                        stop: stop
                    });
                }

                // Add connection to next stop (if not last)
                if (index < stops.length - 1) {
                    const leg = getLegForStops(stop.id, stops[index + 1].id);
                    tree.connections.push({
                        from: stop.id,
                        to: stops[index + 1].id,
                        leg: leg
                    });
                }
            });

            return tree;
        }

        function renderFlowLevel(tree, depth) {
            let html = '';

            tree.levels.forEach((level, index) => {
                const stop = level.stop;
                const isFirst = index === 0;
                const isLast = index === tree.levels.length - 1;

                // Render the node
                html += renderFlowNode(stop, isFirst, isLast, index);

                // Render connection/leg indicator (if not last)
                if (!isLast) {
                    const connection = tree.connections[index];
                    if (connection) {
                        html += renderFlowLegSpacer(connection.leg, stop.id);
                    }
                }
            });

            return html;
        }

        function renderFlowNode(stop, isFirst, isLast, index) {
            const isOrigin = stop.stop_type === 'origin';
            const isDestination = stop.stop_type === 'destination';
            const stopTypeClass = getStopTypeClass(stop.stop_type);

            const totalFees = (stop.fees || []).reduce((sum, f) => sum + (parseFloat(f.estimated_cost) || 0), 0);
            const feeCount = (stop.fees || []).length;
            const locationName = stop.location_name || getDefaultLocationName(stop.stop_type);

            // Meta info for the card
            let metaHtml = '';
            if (feeCount > 0 || totalFees > 0) {
                metaHtml += `
                    <div class="node-meta">
                        ${feeCount > 0 ? `<span class="node-meta-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20m5-17H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H7"/></svg>
                            ${feeCount} fee${feeCount !== 1 ? 's' : ''}
                        </span>` : ''}
                        ${totalFees > 0 ? `<span class="node-meta-item"><strong>$${totalFees.toLocaleString()}</strong></span>` : ''}
                    </div>
                `;
            }

            // Action buttons - simplified, just edit and delete
            let actionsHtml = '';
            if (canEdit) {
                const showDelete = !isOrigin && !isDestination;

                actionsHtml = `
                    <div class="flow-node-actions">
                        <button type="button" class="flow-node-action-btn edit" data-action="edit-node" data-stop-id="${stop.id}" title="Edit Stop">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        ${showDelete ? `<button type="button" class="flow-node-action-btn delete" data-action="delete-node" data-stop-id="${stop.id}" title="Remove Stop">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>` : ''}
                    </div>
                `;
            }

            // Connection ports - in port at top (except for origin), out port at bottom (except for destination)
            const portInHtml = !isOrigin && canEdit ? `<div class="flow-node-port port-in" data-port="in" data-stop-id="${stop.id}"></div>` : '';
            const portOutHtml = !isDestination && canEdit ? `<div class="flow-node-port port-out" data-port="out" data-stop-id="${stop.id}"></div>` : '';

            return `
                <div class="flow-level">
                    <div class="flow-node" data-stop-id="${stop.id}" data-stop-type="${stop.stop_type}" data-index="${index}">
                        ${portInHtml}
                        <div class="flow-node-orb ${stopTypeClass}" data-action="edit-node" data-stop-id="${stop.id}">
                            ${getStopTypeIcon(stop.stop_type)}
                        </div>
                        ${portOutHtml}
                        <div class="flow-node-card">
                            <h4>${escapeHtml(locationName)}</h4>
                            <div class="node-type">${getStopTypeLabel(stop.stop_type)}</div>
                            ${metaHtml}
                        </div>
                        ${actionsHtml}
                    </div>
                </div>
            `;
        }

        function renderFlowLegSpacer(leg, fromStopId) {
            // Just a spacer - the connection badge is rendered by renderFlowConnections
            return '<div class="flow-level-spacer"></div>';
        }

        function renderFlowConnections() {
            const svgContainer = document.getElementById('flowConnectionsSvg');
            const canvasContainer = document.querySelector('.flow-canvas-wrapper');
            if (!svgContainer || !canvasContainer) return;

            // Clear existing paths (keep defs)
            const defsContent = svgContainer.querySelector('defs')?.outerHTML || '';
            svgContainer.innerHTML = defsContent;

            const stops = workingState.stops || [];
            if (stops.length < 2) return;

            // Remove existing connection badges
            document.querySelectorAll('.flow-connection-badge').forEach(el => el.remove());

            // Get all node elements
            const nodes = document.querySelectorAll('.flow-node');
            if (nodes.length < 2) return;

            // Calculate positions and draw connections
            const containerRect = canvasContainer.getBoundingClientRect();
            const legs = workingState.legs || [];

            for (let i = 0; i < nodes.length - 1; i++) {
                const fromNode = nodes[i];
                const toNode = nodes[i + 1];

                const fromStopId = fromNode.dataset.stopId;
                const toStopId = toNode.dataset.stopId;

                const fromOrb = fromNode.querySelector('.flow-node-orb');
                const toOrb = toNode.querySelector('.flow-node-orb');

                if (!fromOrb || !toOrb) continue;

                const fromRect = fromOrb.getBoundingClientRect();
                const toRect = toOrb.getBoundingClientRect();

                // Calculate center points relative to SVG container
                const x1 = fromRect.left + fromRect.width / 2 - containerRect.left;
                const y1 = fromRect.top + fromRect.height / 2 - containerRect.top;
                const x2 = toRect.left + toRect.width / 2 - containerRect.left;
                const y2 = toRect.top + toRect.height / 2 - containerRect.top;

                // Find the leg for this connection
                const leg = legs.find(l => l.from_stop_id == fromStopId && l.to_stop_id == toStopId);

                // Create curved path
                const startY = y1 + 36;
                const endY = y2 - 36;
                const midX = (x1 + x2) / 2;
                const midY = (startY + endY) / 2;

                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');

                // Bezier curve for smooth connection
                const d = `M ${x1} ${startY} C ${x1} ${midY}, ${x2} ${midY}, ${x2} ${endY}`;

                path.setAttribute('d', d);
                path.setAttribute('class', 'flow-connection-path');
                path.setAttribute('data-leg-id', leg ? leg.id : '');
                path.setAttribute('data-from-stop', fromStopId);
                path.setAttribute('data-to-stop', toStopId);

                svgContainer.appendChild(path);

                // Create connection badge at midpoint
                if (leg) {
                    const badge = document.createElement('div');
                    badge.className = 'flow-connection-badge';
                    badge.dataset.legId = leg.id;

                    const trucks = parseInt(leg.trucks_required, 10) || getTotalTrucks();
                    const totalFreight = parseFloat(leg.total_freight_cost) || 0;
                    const transportMode = leg.transport_mode || 'truck';

                    badge.innerHTML = `
                        ${getTransportIconSmall(transportMode)}
                        <span>${trucks} truck${trucks !== 1 ? 's' : ''}</span>
                        ${totalFreight > 0 ? `<span class="badge-cost">$${formatCompactNumber(totalFreight)}</span>` : '<span style="color: var(--gray-400);">Click to configure</span>'}
                    `;

                    badge.style.left = midX + 'px';
                    badge.style.top = midY + 'px';

                    canvasContainer.appendChild(badge);
                }
            }

            // Update SVG size
            svgContainer.style.height = canvasContainer.scrollHeight + 'px';
        }

        // Stats header removed - keeping function stub for compatibility
        function updateFlowCanvasStats() {
            // No longer displaying stats header
        }

        function bindFlowCanvasListeners() {
            const container = document.getElementById('flowCanvas');
            const wrapper = document.getElementById('flowCanvasWrapper');
            const dragLine = document.getElementById('flowDragLine');
            if (!container) return;

            // Use event delegation for clicks
            container.onclick = function(event) {
                const target = event.target.closest('[data-action]');
                if (!target) return;

                const action = target.dataset.action;
                const stopId = target.dataset.stopId;
                const legId = target.dataset.legId;

                event.stopPropagation();

                switch (action) {
                    case 'edit-node':
                        openNodeEditor(stopId);
                        break;
                    case 'delete-node':
                        confirmDeleteNode(stopId);
                        break;
                    case 'edit-leg':
                        openLegEditor(legId);
                        break;
                }
            };

            // Click on connection paths to edit legs
            document.querySelectorAll('.flow-connection-path').forEach(path => {
                path.onclick = function(event) {
                    event.stopPropagation();
                    const legId = this.dataset.legId;
                    if (legId) openLegEditor(legId);
                };
            });

            // Click on connection badges to edit legs
            document.querySelectorAll('.flow-connection-badge').forEach(badge => {
                badge.onclick = function(event) {
                    event.stopPropagation();
                    const legId = this.dataset.legId;
                    if (legId) openLegEditor(legId);
                };
            });

            // ========== DRAG-TO-CONNECT FUNCTIONALITY ==========
            if (!canEdit) return;

            // Mouse down on output port starts drag
            container.querySelectorAll('.flow-node-port.port-out').forEach(port => {
                port.onmousedown = function(event) {
                    event.preventDefault();
                    event.stopPropagation();

                    isDraggingConnection = true;
                    dragSourceStopId = this.dataset.stopId;
                    dragSourcePort = this;

                    // Mark the port as active
                    this.classList.add('active');
                    wrapper.classList.add('dragging');

                    // Set the source node attribute
                    const sourceNode = this.closest('.flow-node');
                    if (sourceNode) sourceNode.setAttribute('data-is-source', 'true');

                    // Get starting position
                    const rect = this.getBoundingClientRect();
                    const wrapperRect = wrapper.getBoundingClientRect();
                    const startX = rect.left + rect.width / 2 - wrapperRect.left;
                    const startY = rect.top + rect.height / 2 - wrapperRect.top;

                    // Show and position drag line
                    if (dragLine) {
                        dragLine.setAttribute('x1', startX);
                        dragLine.setAttribute('y1', startY);
                        dragLine.setAttribute('x2', startX);
                        dragLine.setAttribute('y2', startY);
                        dragLine.style.display = 'block';
                    }
                };
            });

            // Mouse move updates drag line
            if (wrapper) {
                wrapper.onmousemove = function(event) {
                    if (!isDraggingConnection || !dragLine) return;

                    const wrapperRect = wrapper.getBoundingClientRect();
                    const mouseX = event.clientX - wrapperRect.left;
                    const mouseY = event.clientY - wrapperRect.top;

                    dragLine.setAttribute('x2', mouseX);
                    dragLine.setAttribute('y2', mouseY);

                    // Check if hovering over an input port
                    document.querySelectorAll('.flow-node-port.port-in').forEach(port => {
                        const portStopId = port.dataset.stopId;
                        if (portStopId === dragSourceStopId) return; // Can't connect to self

                        const portRect = port.getBoundingClientRect();
                        const portCenterX = portRect.left + portRect.width / 2;
                        const portCenterY = portRect.top + portRect.height / 2;
                        const distance = Math.sqrt(
                            Math.pow(event.clientX - portCenterX, 2) +
                            Math.pow(event.clientY - portCenterY, 2)
                        );

                        if (distance < 30) {
                            port.classList.add('drop-target');
                        } else {
                            port.classList.remove('drop-target');
                        }
                    });
                };

                // Mouse up ends drag
                wrapper.onmouseup = function(event) {
                    if (!isDraggingConnection) return;

                    // Find if we're over an input port
                    let targetStopId = null;
                    document.querySelectorAll('.flow-node-port.port-in.drop-target').forEach(port => {
                        targetStopId = port.dataset.stopId;
                    });

                    // Clean up
                    finishDrag();

                    // If we have a valid target, create/update the connection
                    if (targetStopId && targetStopId !== dragSourceStopId) {
                        createOrUpdateConnection(dragSourceStopId, targetStopId);
                    }
                };

                // Mouse leave cancels drag
                wrapper.onmouseleave = function() {
                    if (isDraggingConnection) {
                        finishDrag();
                    }
                };
            }

            // Also handle mouseup on ports directly
            container.querySelectorAll('.flow-node-port.port-in').forEach(port => {
                port.onmouseup = function(event) {
                    if (!isDraggingConnection) return;

                    event.stopPropagation();
                    const targetStopId = this.dataset.stopId;

                    finishDrag();

                    if (targetStopId && targetStopId !== dragSourceStopId) {
                        createOrUpdateConnection(dragSourceStopId, targetStopId);
                    }
                };
            });
        }

        function finishDrag() {
            isDraggingConnection = false;
            dragSourceStopId = null;

            // Clean up visual state
            const wrapper = document.getElementById('flowCanvasWrapper');
            const dragLine = document.getElementById('flowDragLine');

            if (wrapper) wrapper.classList.remove('dragging');
            if (dragLine) dragLine.style.display = 'none';

            document.querySelectorAll('.flow-node-port.active').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.flow-node-port.drop-target').forEach(p => p.classList.remove('drop-target'));
            document.querySelectorAll('.flow-node[data-is-source]').forEach(n => n.removeAttribute('data-is-source'));
        }

        function createOrUpdateConnection(fromStopId, toStopId) {
            // Check if connection already exists
            let leg = workingState.legs.find(l => l.from_stop_id == fromStopId && l.to_stop_id == toStopId);

            if (!leg) {
                // Create new leg
                leg = getLegForStops(fromStopId, toStopId);
                markAsUnsaved();
            }

            // Re-render and open leg editor
            renderJourneyPlan();
            updateMapFromState();

            // Open the leg editor modal
            setTimeout(() => {
                openLegEditor(leg.id);
            }, 100);
        }

        function openQuickAddStop() {
            // Add a stop before destination
            const stops = workingState.stops || [];
            if (stops.length < 2) return;

            const lastIntermediateStop = stops[stops.length - 2];
            addJourneyStop(lastIntermediateStop.id);
        }

        // ==================== HELPER FUNCTIONS ====================

        function getStopTypeClass(stopType) {
            switch (stopType) {
                case 'origin': return 'origin';
                case 'destination': return 'destination';
                case 'port': return 'port';
                case 'customs': return 'customs';
                default: return 'warehouse';
            }
        }

        function getStopTypeLabel(stopType) {
            switch (stopType) {
                case 'origin': return 'Origin / Manufacturer';
                case 'destination': return 'Final Destination';
                case 'port': return 'Port';
                case 'customs': return 'Customs Facility';
                default: return 'Warehouse';
            }
        }

        function getDefaultLocationName(stopType) {
            switch (stopType) {
                case 'origin': return 'Manufacturer';
                case 'destination': return 'Project Site';
                case 'port': return 'Port Terminal';
                case 'customs': return 'Customs Facility';
                default: return 'Warehouse';
            }
        }

        function getStopTypeIcon(stopType) {
            switch (stopType) {
                case 'origin':
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>';
                case 'destination':
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
                case 'port':
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2 2.4 0 2.4-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M19.38 20A11.6 11.6 0 0 0 21 14l-9-4-9 4"/><path d="M12 10v4"/></svg>';
                case 'customs':
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
                default: // warehouse
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';
            }
        }

        function getTransportIconSmall(mode) {
            switch (mode) {
                case 'ocean':
                    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2 2.4 0 2.4-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M12 10v4"/></svg>';
                case 'rail':
                    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/></svg>';
                case 'air':
                    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2"/></svg>';
                default:
                    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
            }
        }

        // ==================== NODE EDITOR MODAL ====================

        function openNodeEditor(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;

            currentEditingNode = stop;

            const modal = document.getElementById('nodeEditorModal');
            const title = document.getElementById('nodeModalTitle');
            const subtitle = document.getElementById('nodeModalSubtitle');
            const icon = document.getElementById('nodeModalIcon');
            const body = document.getElementById('nodeModalBody');

            const isOrigin = stop.stop_type === 'origin';
            const isDestination = stop.stop_type === 'destination';
            const isEditable = !isOrigin && !isDestination;

            // Update header
            title.textContent = isOrigin ? 'Origin Details' : (isDestination ? 'Destination Details' : 'Edit Stop');
            subtitle.textContent = isOrigin ? 'Manufacturing location' : (isDestination ? 'Final delivery point' : 'Configure this stop');

            // Update icon color based on type
            icon.className = 'flow-modal-header-icon';
            if (isOrigin) icon.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
            else if (isDestination) icon.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
            else icon.style.background = 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)';

            // Build form content
            let html = '';

            if (isEditable) {
                // Stop type selector
                html += `
                    <div class="stop-type-selector">
                        <div class="stop-type-option ${stop.stop_type === 'warehouse' ? 'selected' : ''}" data-type="warehouse" onclick="selectStopType('warehouse')">
                            <div class="type-icon warehouse"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg></div>
                            <span class="type-label">Warehouse</span>
                        </div>
                        <div class="stop-type-option ${stop.stop_type === 'port' ? 'selected' : ''}" data-type="port" onclick="selectStopType('port')">
                            <div class="type-icon port"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2"/><path d="M12 10v4"/></svg></div>
                            <span class="type-label">Port</span>
                        </div>
                        <div class="stop-type-option ${stop.stop_type === 'customs' ? 'selected' : ''}" data-type="customs" onclick="selectStopType('customs')">
                            <div class="type-icon customs"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></div>
                            <span class="type-label">Customs</span>
                        </div>
                    </div>
                `;
            }

            // Location name
            html += `
                <div class="modal-form-group">
                    <label class="modal-form-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Location Name
                    </label>
                    <input type="text" class="modal-form-input" id="nodeLocationName" value="${escapeHtml(stop.location_name || '')}" placeholder="${getDefaultLocationName(stop.stop_type)}" ${!isEditable ? 'readonly' : ''}>
                </div>
            `;

            // Address
            html += `
                <div class="modal-form-group">
                    <label class="modal-form-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/></svg>
                        Address
                    </label>
                    <input type="text" class="modal-form-input address-autocomplete" id="nodeLocationAddress" data-stop-id="${stop.id}" value="${escapeHtml(stop.location_address || '')}" placeholder="Start typing to search..." ${isOrigin || isDestination ? 'readonly style="background: var(--gray-50);"' : ''}>
                </div>
            `;

            // Fees section (only for warehouses/ports)
            if (isEditable) {
                const fees = stop.fees || [];
                const totalFees = fees.reduce((sum, f) => sum + (parseFloat(f.estimated_cost) || 0), 0);

                html += `
                    <div class="modal-fees-section">
                        <div class="modal-fees-header">
                            <h4>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                Location Fees
                            </h4>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="addFeeToModal()">+ Add Fee</button>
                        </div>
                        <div class="modal-fees-column-headers" id="modalFeesHeaders" style="${fees.length === 0 ? 'display: none;' : ''}">
                            <span>Fee Name</span>
                            <span>Amount</span>
                            <span>Billing Unit</span>
                            <span>When Charged</span>
                            <span></span>
                        </div>
                        <div id="modalFeesList">
                            ${renderModalFees(fees)}
                        </div>
                        <div class="modal-total-row">
                            <span class="modal-total-label">Estimated Total:</span>
                            <span class="modal-total-value" id="modalFeesTotal">$${totalFees.toLocaleString()}</span>
                        </div>
                    </div>
                `;

                // Customs clearance checkbox
                html += `
                    <div class="modal-form-group" style="margin-top: 20px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" id="nodeCustomsClearance" ${stop.is_customs_clearance ? 'checked' : ''}>
                            <span>Customs clearance at this location</span>
                        </label>
                    </div>
                `;
            }

            body.innerHTML = html;

            // Initialize address autocomplete for modal
            const addressInput = body.querySelector('.address-autocomplete');
            if (addressInput && !isOrigin && !isDestination) {
                initializeAddressAutocomplete(addressInput, (placeData) => {
                    if (currentEditingNode) {
                        currentEditingNode.location_address = placeData.address;
                        currentEditingNode.latitude = placeData.latitude;
                        currentEditingNode.longitude = placeData.longitude;
                    }
                });
            }

            // Show modal
            modal.classList.add('active');
        }

        function renderModalFees(fees) {
            if (!fees || fees.length === 0) {
                return '<p style="color: var(--gray-500); font-size: 0.9em; text-align: center; padding: 16px;">No fees added yet</p>';
            }

            return fees.map((fee, index) => `
                <div class="modal-fee-item" data-fee-index="${index}">
                    <input type="text" class="modal-form-input" value="${escapeHtml(fee.fee_name || '')}" placeholder="Fee name" onchange="updateModalFee(${index}, 'fee_name', this.value)">
                    <input type="number" class="modal-form-input" value="${fee.rate || ''}" placeholder="$0" step="0.01" onchange="updateModalFee(${index}, 'rate', this.value)">
                    <select class="modal-form-input" onchange="updateModalFee(${index}, 'rate_unit', this.value)">
                        <option value="per_pallet" ${fee.rate_unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                        <option value="per_truck" ${fee.rate_unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                        <option value="per_sqft" ${fee.rate_unit === 'per_sqft' ? 'selected' : ''}>Per SQFT</option>
                        <option value="flat" ${fee.rate_unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                    </select>
                    <select class="modal-form-input" onchange="updateModalFee(${index}, 'fee_type', this.value)">
                        <option value="receiving" ${fee.fee_type === 'receiving' ? 'selected' : ''}>On Entry</option>
                        <option value="outbound" ${fee.fee_type === 'outbound' ? 'selected' : ''}>On Exit</option>
                        <option value="storage" ${fee.fee_type === 'storage' ? 'selected' : ''}>Monthly</option>
                        <option value="one_time" ${fee.fee_type === 'one_time' ? 'selected' : ''}>One Time</option>
                    </select>
                    <button type="button" class="modal-fee-remove" onclick="removeModalFee(${index})">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            `).join('');
        }

        function selectStopType(type) {
            if (!currentEditingNode) return;
            currentEditingNode.stop_type = type;

            // Update UI
            document.querySelectorAll('.stop-type-option').forEach(el => {
                el.classList.toggle('selected', el.dataset.type === type);
            });
        }

        function addFeeToModal() {
            if (!currentEditingNode) return;
            if (!Array.isArray(currentEditingNode.fees)) currentEditingNode.fees = [];

            currentEditingNode.fees.push({
                fee_type: 'receiving',
                fee_name: '',
                rate: '',
                rate_unit: 'per_pallet',
                estimated_cost: 0
            });

            // Re-render fees list
            const list = document.getElementById('modalFeesList');
            if (list) list.innerHTML = renderModalFees(currentEditingNode.fees);

            // Show headers if we now have fees
            const headers = document.getElementById('modalFeesHeaders');
            if (headers && currentEditingNode.fees.length > 0) {
                headers.style.display = '';
            }

            recalculateModalFees();
        }

        function removeModalFee(index) {
            if (!currentEditingNode || !currentEditingNode.fees) return;
            currentEditingNode.fees.splice(index, 1);

            const list = document.getElementById('modalFeesList');
            if (list) list.innerHTML = renderModalFees(currentEditingNode.fees);

            // Hide headers if no fees left
            const headers = document.getElementById('modalFeesHeaders');
            if (headers) {
                headers.style.display = currentEditingNode.fees.length === 0 ? 'none' : '';
            }

            recalculateModalFees();
        }

        function updateModalFee(index, field, value) {
            if (!currentEditingNode || !currentEditingNode.fees || !currentEditingNode.fees[index]) return;
            currentEditingNode.fees[index][field] = value;

            // Recalculate fee estimate
            currentEditingNode.fees[index].estimated_cost = calculateFeeEstimate(currentEditingNode.fees[index], currentEditingNode);

            recalculateModalFees();
        }

        function recalculateModalFees() {
            if (!currentEditingNode) return;
            const fees = currentEditingNode.fees || [];
            const totalFees = fees.reduce((sum, f) => sum + (parseFloat(f.estimated_cost) || 0), 0);

            const totalEl = document.getElementById('modalFeesTotal');
            if (totalEl) totalEl.textContent = '$' + totalFees.toLocaleString();
        }

        function closeNodeEditor() {
            const modal = document.getElementById('nodeEditorModal');
            modal.classList.remove('active');
            currentEditingNode = null;
        }

        function saveNodeEditor() {
            if (!currentEditingNode) {
                closeNodeEditor();
                return;
            }

            // Get values from form
            const nameInput = document.getElementById('nodeLocationName');
            const addressInput = document.getElementById('nodeLocationAddress');
            const customsCheckbox = document.getElementById('nodeCustomsClearance');

            if (nameInput) currentEditingNode.location_name = nameInput.value;
            if (addressInput) currentEditingNode.location_address = addressInput.value;
            if (customsCheckbox) currentEditingNode.is_customs_clearance = customsCheckbox.checked ? 1 : 0;

            // Mark as saved
            currentEditingNode.is_saved = true;

            markAsUnsaved();
            closeNodeEditor();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();

            showToast('Stop updated successfully', 'success');
        }

        // ==================== LEG EDITOR MODAL ====================

        // openLegEditor delegates to the static-DOM version
        function openLegEditor(legId) {
            openLegEditorModal(legId);
        }

        function selectTransportMode(mode) {
            selectLegTransportMode(mode);
        }

        function closeLegEditor() {
            const modal = document.getElementById('legEditorModal');
            if (modal) modal.classList.remove('active');
            currentEditingLeg = null;
        }

        function saveLegEditor() {
            // Get leg ID and stop IDs from form
            const legId = document.getElementById('editLegId')?.value;
            const fromStopId = document.getElementById('legFromStopId')?.value;
            const toStopId = document.getElementById('legToStopId')?.value;

            // Find the leg to update, or use currentEditingLeg
            let leg = currentEditingLeg;
            if (!leg && legId) {
                leg = workingState.legs.find(l => l.id == legId);
            }
            if (!leg && fromStopId && toStopId) {
                leg = workingState.legs.find(l => l.from_stop_id == fromStopId && l.to_stop_id == toStopId);
            }

            if (!leg) {
                closeLegEditor();
                return;
            }

            // Get values from form
            const trucks = parseInt(document.getElementById('legTrucksRequired')?.value, 10) || getTotalTrucks();
            const startDate = document.getElementById('legStartDate')?.value || '';
            const endDate = document.getElementById('legEndDate')?.value || '';
            const deliveryRate = parseFloat(document.getElementById('legDeliveryRate')?.value) || 0;
            const rateUnit = document.getElementById('legRateUnit')?.value || 'per_week';
            const freightCost = parseFloat(document.getElementById('legFreightCost')?.value) || 0;
            const accessorialCost = parseFloat(document.getElementById('legAccessorialCost')?.value) || 0;
            const transportMode = document.getElementById('legTransportMode')?.value || 'truck';
            const triggersMilestone = document.getElementById('legTriggersMilestone')?.value || '';
            const notes = document.getElementById('legNotes')?.value || '';

            // Update leg properties
            leg.trucks_required = trucks;
            leg.start_date = startDate;
            leg.end_date = endDate;
            leg.delivery_rate = deliveryRate;
            leg.delivery_rate_unit = rateUnit;
            leg.freight_cost_per_truck = freightCost;
            leg.accessorial_cost_per_truck = accessorialCost;
            leg.transport_mode = transportMode;
            leg.triggers_milestone = triggersMilestone;
            leg.notes = notes;
            leg.total_freight_cost = trucks * (freightCost + accessorialCost);
            leg.is_configured = true;

            markAsUnsaved();
            closeLegEditor();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
            updateBadges();

            showToast('Transport configured successfully', 'success');
        }

        // ==================== ADD STOP MODAL ====================

        function openAddStopOptionsModal(afterStopId) {
            currentAddStopParentId = afterStopId;
            const modal = document.getElementById('addStopModal');
            const subtitle = document.getElementById('addStopSubtitle');

            const stop = workingState.stops.find(s => s.id == afterStopId);
            subtitle.textContent = `Adding after ${stop?.location_name || 'this stop'}`;

            modal.classList.add('active');
        }

        function closeAddStopModal() {
            const modal = document.getElementById('addStopModal');
            modal.classList.remove('active');
            currentAddStopParentId = null;
        }

        function addSingleStop() {
            closeAddStopModal();

            if (!currentAddStopParentId) return;

            // Use existing addJourneyStop function
            addJourneyStop(currentAddStopParentId);
        }

        function addBranchSplit() {
            closeAddStopModal();
            openBranchConfig();
        }

        // ==================== BRANCH CONFIGURATION MODAL ====================

        function openBranchConfig() {
            const modal = document.getElementById('branchConfigModal');
            const totalTrucksEl = document.getElementById('branchTotalTrucks');
            const listEl = document.getElementById('branchConfigList');

            const totalTrucks = getTotalTrucks();
            totalTrucksEl.textContent = totalTrucks;

            // Initialize with 2 branches
            branchConfigData = {
                parentStopId: currentAddStopParentId,
                branches: [
                    { name: 'Branch A', trucks: Math.floor(totalTrucks / 2) },
                    { name: 'Branch B', trucks: Math.ceil(totalTrucks / 2) }
                ]
            };

            renderBranchConfigList();
            updateBranchAllocation();

            modal.classList.add('active');
        }

        function renderBranchConfigList() {
            const listEl = document.getElementById('branchConfigList');
            if (!listEl) return;

            listEl.innerHTML = branchConfigData.branches.map((branch, index) => `
                <div class="branch-config-item" data-branch-index="${index}">
                    <input type="text" class="modal-form-input branch-name-input" value="${escapeHtml(branch.name)}" placeholder="Branch name" onchange="updateBranchName(${index}, this.value)">
                    <input type="number" class="modal-form-input branch-trucks-input" value="${branch.trucks}" min="1" placeholder="Trucks" onchange="updateBranchTrucks(${index}, this.value)">
                    ${branchConfigData.branches.length > 2 ? `
                        <button type="button" class="branch-remove-btn" onclick="removeBranch(${index})">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    ` : '<div style="width: 32px;"></div>'}
                </div>
            `).join('');
        }

        function updateBranchName(index, value) {
            if (branchConfigData.branches[index]) {
                branchConfigData.branches[index].name = value;
            }
        }

        function updateBranchTrucks(index, value) {
            if (branchConfigData.branches[index]) {
                branchConfigData.branches[index].trucks = parseInt(value, 10) || 0;
                updateBranchAllocation();
            }
        }

        function addAnotherBranch() {
            branchConfigData.branches.push({
                name: `Branch ${String.fromCharCode(65 + branchConfigData.branches.length)}`,
                trucks: 0
            });
            renderBranchConfigList();
            updateBranchAllocation();
        }

        function removeBranch(index) {
            if (branchConfigData.branches.length <= 2) return;
            branchConfigData.branches.splice(index, 1);
            renderBranchConfigList();
            updateBranchAllocation();
        }

        function updateBranchAllocation() {
            const totalTrucks = getTotalTrucks();
            const allocated = branchConfigData.branches.reduce((sum, b) => sum + (b.trucks || 0), 0);
            const remaining = totalTrucks - allocated;
            const percentage = Math.min((allocated / totalTrucks) * 100, 100);

            const fillEl = document.getElementById('branchAllocationFill');
            const statusEl = document.getElementById('branchAllocationStatus');
            const saveBtn = document.getElementById('saveBranchBtn');

            if (fillEl) fillEl.style.width = percentage + '%';
            if (statusEl) {
                statusEl.innerHTML = `
                    <span class="allocated">${allocated} trucks allocated</span>
                    <span class="remaining" style="color: ${remaining < 0 ? 'var(--danger)' : 'var(--gray-500)'};">${remaining >= 0 ? remaining + ' remaining' : Math.abs(remaining) + ' over!'}</span>
                `;
            }

            // Disable save if allocation doesn't match
            if (saveBtn) {
                saveBtn.disabled = allocated !== totalTrucks;
                saveBtn.style.opacity = allocated !== totalTrucks ? '0.5' : '1';
            }
        }

        function closeBranchConfig() {
            const modal = document.getElementById('branchConfigModal');
            modal.classList.remove('active');
            branchConfigData = { branches: [], parentStopId: null };
        }

        function saveBranchConfig() {
            // For now, just add the first branch as a regular stop
            // Full branching implementation would require data structure changes
            closeBranchConfig();

            showToast('Branch splitting is coming soon! Adding single stop for now.', 'info');

            // Add single stop as fallback
            if (branchConfigData.parentStopId) {
                addJourneyStop(branchConfigData.parentStopId);
            }
        }

        // ==================== MERGE MODAL ====================

        function closeMergeModal() {
            const modal = document.getElementById('mergeModal');
            modal.classList.remove('active');
        }

        function confirmMerge() {
            closeMergeModal();
            showToast('Branches merged to destination', 'success');
        }

        // ==================== DELETE CONFIRMATION ====================

        function confirmDeleteNode(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;

            if (confirm(`Are you sure you want to remove "${stop.location_name || 'this stop'}" from the journey?`)) {
                removeJourneyStop(stopId);
                showToast('Stop removed from journey', 'success');
            }
        }

        // Keep the old renderJourneyPlan container check for backwards compatibility
        function renderJourneyPlanLegacy() {
            const container = document.getElementById('journeyFlow');
            const emptyState = document.getElementById('journeyEmpty');
            if (!container) return;

            const disabledAttr = canEdit ? '' : 'disabled';
            const stops = workingState.stops || [];

            if (stops.length < 2) {
                container.innerHTML = '';
                if (emptyState) emptyState.style.display = 'block';
                return;
            }

            if (emptyState) emptyState.style.display = 'none';

            let html = '';

            stops.forEach((stop, index) => {
                const isFirst = index === 0;
                const isLast = index === stops.length - 1;
                const isOrigin = stop.stop_type === 'origin';
                const isDestination = stop.stop_type === 'destination';
                const isWarehouse = !isOrigin && !isDestination;

                const dotClass = isOrigin ? 'origin' : (isDestination ? 'destination' : (stop.stop_type === 'port' ? 'port' : 'warehouse'));
                const stepNumber = index + 1;

                const milestone = getMilestoneForStop(stop);
                const totalFees = (stop.fees || []).reduce((sum, f) => sum + (f.estimated_cost || 0), 0);

                // Build badges
                let badges = '';
                if (isOrigin) {
                    badges += '<span class="journey-badge milestone">Start</span>';
                }
                if (isDestination) {
                    badges += '<span class="journey-badge milestone">Final Delivery</span>';
                }
                if (stop.is_customs_clearance) {
                    badges += '<span class="journey-badge customs">Customs</span>';
                }
                if (!isOrigin && !isDestination && milestone.value) {
                    badges += `<span class="journey-badge milestone">${milestone.label}</span>`;
                }

                // Build fees section for warehouses - Updated format to match add_warehouse.php
                // Order: Fee Name → When Charged (trigger) → Rate → Per (unit)
                let feesHtml = '';
                if (isWarehouse) {
                    const feeRows = (stop.fees || []).map((fee, feeIndex) => `
                        <tr>
                            <td>
                                <input type="text" class="delivery-input" data-fee-field="fee_name" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" value="${escapeHtml(fee.fee_name || '')}" placeholder="Fee name" ${disabledAttr}>
                            </td>
                            <td>
                                <select class="delivery-select" data-fee-field="fee_type" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="receiving" ${fee.fee_type === 'receiving' ? 'selected' : ''}>On Entry</option>
                                    <option value="outbound" ${fee.fee_type === 'outbound' ? 'selected' : ''}>On Exit</option>
                                    <option value="storage" ${fee.fee_type === 'storage' ? 'selected' : ''}>Monthly</option>
                                    <option value="one_time" ${fee.fee_type === 'one_time' ? 'selected' : ''}>One Time</option>
                                </select>
                            </td>
                            <td class="fee-amount-col">
                                <input type="number" class="delivery-input" data-fee-field="rate" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" value="${fee.rate || ''}" placeholder="$0" step="0.01" ${disabledAttr}>
                            </td>
                            <td>
                                <select class="delivery-select" data-fee-field="rate_unit" data-stop-id="${stop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="per_pallet" ${fee.rate_unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                                    <option value="per_truck" ${fee.rate_unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                                    <option value="per_sqft" ${fee.rate_unit === 'per_sqft' ? 'selected' : ''}>Per SQFT</option>
                                    <option value="flat" ${fee.rate_unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                                </select>
                            </td>
                            <td class="fee-action-col">
                                ${canEdit ? `<button type="button" class="fee-remove-btn" data-action="remove-fee" data-stop-id="${stop.id}" data-fee-index="${feeIndex}">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>` : ''}
                            </td>
                        </tr>
                    `).join('');

                    const feeTableHtml = feeRows ? `
                        <table class="fee-table-journey">
                            <thead>
                                <tr>
                                    <th>Fee Name</th>
                                    <th>When Charged</th>
                                    <th>Rate ($)</th>
                                    <th>Per</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${feeRows}
                            </tbody>
                        </table>
                    ` : '<p style="margin: 0; color: #6c757d; font-size: 0.9em;">No fees added yet.</p>';

                    feesHtml = `
                        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e3e7ea;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <strong style="font-size: 0.9em; color: #293E4C;">Warehouse Fees</strong>
                                ${canEdit ? `<button type="button" class="btn btn-sm btn-secondary" data-action="add-fee" data-stop-id="${stop.id}">+ Add Fee</button>` : ''}
                            </div>
                            ${feeTableHtml}
                            <div style="margin-top: 12px;">
                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                    <input type="checkbox" data-stop-id="${stop.id}" data-stop-field="is_customs_clearance" ${stop.is_customs_clearance ? 'checked' : ''} ${disabledAttr}>
                                    <span style="font-size: 0.9em;">Customs clearance at this location</span>
                                </label>
                            </div>
                            ${totalFees > 0 ? `<div style="margin-top: 8px; text-align: right; font-weight: 600; color: #488C9A;">Est. Total: $${totalFees.toLocaleString()}</div>` : ''}
                        </div>
                    `;
                }

                // Build address input with autocomplete
                const addressInput = isOrigin || isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(stop.location_address || (isOrigin ? 'From manufacturer location' : 'Project address'))}" readonly style="background: #f8f9fa;">`
                    : `<div class="address-input-wrapper" id="address-wrapper-${stop.id}">
                        <input type="text" class="delivery-input address-autocomplete" data-stop-id="${stop.id}" data-stop-field="location_address" value="${escapeHtml(stop.location_address || '')}" placeholder="Start typing to search for address..." ${disabledAttr}>
                        <div class="address-loading"></div>
                        <svg class="address-verified" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        <div class="address-error">Please select a valid address from the suggestions</div>
                    </div>`;

                // Build node card content
                let nodeContent = '';

                if (isOrigin) {
                    nodeContent = `
                        <div class="journey-node-header">
                            <div>
                                <h4 class="journey-node-title">${escapeHtml(stop.location_name || 'Manufacturer')}</h4>
                                <div class="journey-node-type">Origin / Manufacturer</div>
                            </div>
                            <div class="journey-node-badges">${badges}</div>
                        </div>
                        <div class="journey-node-details">
                            <div class="delivery-field">
                                <label>Location</label>
                                ${addressInput}
                            </div>
                        </div>
                    `;
                } else if (isDestination) {
                    nodeContent = `
                        <div class="journey-node-header">
                            <div>
                                <h4 class="journey-node-title">${escapeHtml(stop.location_name || 'Project Site')}</h4>
                                <div class="journey-node-type">Final Destination</div>
                            </div>
                            <div class="journey-node-badges">${badges}</div>
                        </div>
                        <div class="journey-node-details">
                            <div class="delivery-field">
                                <label>Location</label>
                                ${addressInput}
                            </div>
                        </div>
                    `;
                } else {
                    // Warehouse/Port stop - Now collapsible
                    const stopTypeBadge = stop.stop_type === 'port' ? 'port' : (stop.stop_type === 'customs' ? 'customs' : 'warehouse');
                    const stopTypeLabel = stop.stop_type === 'port' ? 'Port' : (stop.stop_type === 'customs' ? 'Customs Facility' : 'Warehouse');
                    const feeCount = (stop.fees || []).length;
                    const collapsedClass = stop.is_collapsed ? 'collapsed' : '';
                    const savedIndicator = stop.is_saved
                        ? '<span class="saved-indicator" style="margin-left: 8px; display: inline-flex; align-items: center;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></span>'
                        : '';

                    // Calculate estimated duration if arrival dates are available
                    const nextStopIdx = stops.indexOf(stop) + 1;
                    const nextStop = stops[nextStopIdx];
                    let durationDisplay = '';
                    if (stop.estimated_arrival_date && nextStop?.estimated_arrival_date) {
                        const arrival = new Date(stop.estimated_arrival_date);
                        const departure = new Date(nextStop.estimated_arrival_date);
                        const daysStored = Math.ceil((departure - arrival) / (1000 * 60 * 60 * 24));
                        if (daysStored > 0) {
                            durationDisplay = daysStored > 30 ? `${Math.round(daysStored / 30)} months` : `${daysStored} days`;
                        }
                    }

                    nodeContent = `
                        <div class="warehouse-stop-card ${collapsedClass}" data-stop-id="${stop.id}">
                            <div class="warehouse-stop-header" onclick="toggleWarehouseCard('${stop.id}')">
                                <div class="warehouse-stop-info">
                                    <div class="warehouse-stop-title-row">
                                        <h4 class="warehouse-stop-title">${escapeHtml(stop.location_name || 'Unnamed ' + stopTypeLabel)}</h4>
                                        <span class="warehouse-stop-type-badge ${stopTypeBadge}">${stopTypeLabel}</span>
                                        ${badges}
                                        ${savedIndicator}
                                    </div>
                                    <div class="warehouse-stop-summary">
                                        <div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                                            <span>${feeCount} fee${feeCount !== 1 ? 's' : ''}</span>
                                        </div>
                                        ${totalFees > 0 ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                            <strong>$${totalFees.toLocaleString()}</strong>
                                        </div>` : ''}
                                        ${durationDisplay ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <span>${durationDisplay}</span>
                                        </div>` : ''}
                                        ${stop.location_address ? `<div class="warehouse-stop-summary-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <span style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(stop.location_address)}</span>
                                        </div>` : ''}
                                    </div>
                                </div>
                                <button type="button" class="warehouse-stop-toggle">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </div>
                            <div class="warehouse-stop-body">
                                <div class="journey-node-details">
                                    <div class="delivery-field">
                                        <label>Stop Type</label>
                                        <select class="delivery-select" data-stop-id="${stop.id}" data-stop-field="stop_type" ${disabledAttr}>
                                            <option value="warehouse" ${stop.stop_type === 'warehouse' ? 'selected' : ''}>Warehouse</option>
                                            <option value="port" ${stop.stop_type === 'port' ? 'selected' : ''}>Port</option>
                                            <option value="customs" ${stop.stop_type === 'customs' ? 'selected' : ''}>Customs Facility</option>
                                        </select>
                                    </div>
                                    <div class="delivery-field">
                                        <label>Location Name</label>
                                        <input type="text" class="delivery-input" data-stop-id="${stop.id}" data-stop-field="location_name" value="${escapeHtml(stop.location_name || '')}" placeholder="e.g., Houston Distribution Center" ${disabledAttr}>
                                    </div>
                                    <div class="delivery-field" style="grid-column: 1 / -1;">
                                        <label>Address</label>
                                        ${addressInput}
                                    </div>
                                </div>
                                ${feesHtml}
                                ${canEdit ? `
                                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
                                    <button type="button" class="btn btn-sm btn-danger" data-action="remove-stop" data-stop-id="${stop.id}">Remove This Stop</button>
                                    <button type="button" class="btn btn-sm btn-primary" data-action="save-stop" data-stop-id="${stop.id}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg>
                                        Save &amp; Next
                                    </button>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }

                // Build the node HTML
                if (isWarehouse) {
                    // Warehouse/Port uses the new collapsible card structure directly
                    html += `
                        <div class="journey-node" data-stop-id="${stop.id}">
                            <div class="journey-node-indicator">
                                <div class="journey-node-dot ${dotClass}">${stepNumber}</div>
                                ${!isLast ? '<div class="journey-connector"></div>' : ''}
                            </div>
                            <div class="journey-node-content">
                                ${nodeContent}
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="journey-node" data-stop-id="${stop.id}">
                            <div class="journey-node-indicator">
                                <div class="journey-node-dot ${dotClass}">${stepNumber}</div>
                                ${!isLast ? '<div class="journey-connector"></div>' : ''}
                            </div>
                            <div class="journey-node-content">
                                <div class="journey-node-card">
                                    ${nodeContent}
                                </div>
                            </div>
                        </div>
                    `;
                }

                // Add leg card between stops (except after last stop)
                if (!isLast) {
                    const nextStop = stops[index + 1];
                    const leg = getLegForStops(stop.id, nextStop.id);
                    const maxTrucks = getTotalTrucks();
                    const trucks = parseInt(leg.trucks_required, 10) || maxTrucks;
                    const totalFreight = leg.total_freight_cost || 0;
                    const cadence = parseInt(leg.delivery_rate, 10) || 0;
                    const cadenceUnit = leg.delivery_rate_unit || 'per_week';

                    // Calculate end date based on cadence
                    let endDateDisplay = '';
                    if (leg.start_date && cadence > 0) {
                        const calculatedEnd = calculateEndDate(leg.start_date, cadence, cadenceUnit, trucks);
                        if (calculatedEnd) {
                            endDateDisplay = `<span class="end-date-display">→ ${formatDate(calculatedEnd)}</span>`;
                        }
                    }

                    const transportIcon = getTransportIcon(leg.transport_mode);

                    html += `
                        <div class="journey-leg-card" data-leg-id="${leg.id}">
                            <div class="journey-leg-icon">${transportIcon}</div>
                            <div class="journey-leg-details">
                                <div class="journey-leg-field">
                                    <label>Transport</label>
                                    <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="transport_mode" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                        <option value="truck" ${leg.transport_mode === 'truck' ? 'selected' : ''}>Truck</option>
                                        <option value="ocean" ${leg.transport_mode === 'ocean' ? 'selected' : ''}>Ocean</option>
                                        <option value="rail" ${leg.transport_mode === 'rail' ? 'selected' : ''}>Rail</option>
                                        <option value="air" ${leg.transport_mode === 'air' ? 'selected' : ''}>Air</option>
                                    </select>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Trucks <span class="truck-info-badge">${maxTrucks} max</span></label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="trucks_required" value="${leg.trucks_required || ''}" placeholder="${maxTrucks}" min="1" max="${maxTrucks}" style="padding: 6px 10px; font-size: 0.9em; width: 80px;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Cadence</label>
                                    <div class="cadence-field">
                                        <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="delivery_rate" value="${cadence || ''}" placeholder="0" min="1" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                        <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="delivery_rate_unit" style="padding: 6px 8px; font-size: 0.85em;" ${disabledAttr}>
                                            <option value="per_week" ${cadenceUnit === 'per_week' ? 'selected' : ''}>/week</option>
                                            <option value="per_day" ${cadenceUnit === 'per_day' ? 'selected' : ''}>/day</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Start Date ${endDateDisplay}</label>
                                    <input type="text" class="delivery-input flatpickr-date" data-leg-id="${leg.id}" data-leg-field="start_date" value="${leg.start_date || ''}" placeholder="Select date" style="padding: 6px 10px; font-size: 0.9em;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Freight/Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="freight_cost_per_truck" value="${leg.freight_cost_per_truck || ''}" placeholder="$0" step="0.01" style="padding: 6px 10px; font-size: 0.9em; width: 100px;" ${disabledAttr}>
                                </div>
                                <div class="journey-leg-field">
                                    <label>Total Freight</label>
                                    <div class="value" style="font-weight: 600; color: #488C9A;">$${totalFreight.toLocaleString()}</div>
                                </div>
                            </div>
                        </div>
                    `;

                    // Add "Add Stop Here" button only AFTER the last intermediate stop (before destination)
                    // This prevents showing multiple buttons (one before and one after)
                    const isLastStopBeforeDestination = nextStop && nextStop.stop_type === 'destination';

                    if (canEdit && isLastStopBeforeDestination) {
                        html += `
                            <div class="journey-add-stop">
                                <button type="button" class="journey-add-stop-btn" data-action="add-warehouse-after" data-stop-id="${stop.id}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    Add Stop Here
                                </button>
                            </div>
                        `;
                    }
                }
            });

            container.innerHTML = html;

            // Initialize date pickers and autocomplete
            initializeDatePickers();
            initializeAddressAutocompletes();
            bindJourneyPlanListeners();

            // Update badges
            updateBadges();
            updateStepperState();
        }

        function getTransportIcon(mode) {
            switch (mode) {
                case 'ocean':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2 2.4 0 2.4-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M19.38 20A11.6 11.6 0 0 0 21 14l-9-4-9 4c0 2.9.94 5.34 2.81 7.76"/><path d="M19 13V7a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v6"/><path d="M12 10v4"/></svg>';
                case 'rail':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/><path d="m8 19-2 3"/><path d="m18 22-2-3"/><path d="M8 15h0"/><path d="M16 15h0"/></svg>';
                case 'air':
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>';
                default: // truck
                    return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>';
            }
        }

        function initializeAddressAutocompletes() {
            document.querySelectorAll('.address-autocomplete').forEach(input => {
                const stopId = input.dataset.stopId;

                initializeAddressAutocomplete(input, (placeData) => {
                    const stop = workingState.stops.find(s => s.id == stopId);
                    if (stop) {
                        stop.location_address = placeData.address;
                        stop.latitude = placeData.latitude;
                        stop.longitude = placeData.longitude;

                        markAsUnsaved();
                        updateMapFromState();
                    }
                });
            });
        }

        function bindJourneyPlanListeners() {
            const container = document.getElementById('journeyFlow');
            if (!container) return;

            container.onchange = function(event) {
                const target = event.target;
                if (target.dataset.stopField) {
                    updateJourneyStopField(target);
                } else if (target.dataset.legField) {
                    updateJourneyLegField(target);
                } else if (target.dataset.feeField) {
                    updateJourneyFeeField(target);
                }
            };

            container.onclick = function(event) {
                const actionButton = event.target.closest('[data-action]');
                if (!actionButton) return;
                const action = actionButton.dataset.action;
                if (action === 'add-fee') {
                    addJourneyFee(actionButton.dataset.stopId);
                } else if (action === 'remove-fee') {
                    removeJourneyFee(actionButton.dataset.stopId, parseInt(actionButton.dataset.feeIndex, 10));
                } else if (action === 'remove-stop') {
                    removeJourneyStop(actionButton.dataset.stopId);
                } else if (action === 'add-warehouse' || action === 'add-warehouse-after') {
                    addJourneyStop(actionButton.dataset.stopId);
                } else if (action === 'save-stop') {
                    saveAndCollapseStop(actionButton.dataset.stopId);
                }
            };
        }

        function saveAndCollapseStop(stopId) {
            // Capture current form state first
            captureJourneyFormState();

            // Sync state to recalculate fees
            syncPlanState();

            const stops = workingState.stops || [];
            let nextStopId = null;
            const currentIndex = stops.findIndex(stop => stop.id == stopId);
            if (currentIndex >= 0) {
                stops[currentIndex].is_collapsed = true;
                stops[currentIndex].is_saved = true;

                for (let i = currentIndex + 1; i < stops.length; i++) {
                    if (!['origin', 'destination'].includes(stops[i].stop_type)) {
                        stops[i].is_collapsed = false;
                        nextStopId = stops[i].id;
                        break;
                    }
                }
            }

            // Re-render to update summary info
            renderJourneyPlan();
            updateTimelineChart();
            showToast('Stop updated. Remember to save the projection!', 'success');

            if (nextStopId) {
                setTimeout(() => {
                    const nextCard = document.querySelector(`.warehouse-stop-card[data-stop-id="${nextStopId}"]`);
                    if (nextCard) {
                        nextCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 100);
            } else {
                // Switch to map view within logistics plan
                switchLogisticsView('map');
                const logisticsSection = document.querySelector('[data-section="logistics-plan"]');
                if (logisticsSection) {
                    logisticsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        function updateJourneyStopField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop) return;

            const field = element.dataset.stopField;
            const value = element.type === 'checkbox' ? (element.checked ? 1 : 0) : element.value;
            stop[field] = value;
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function updateJourneyLegField(element) {
            const leg = workingState.legs.find(l => l.id == element.dataset.legId);
            if (!leg) return;

            const field = element.dataset.legField;
            let value = element.value;
            if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                value = value === '' ? '' : parseFloat(value);
            }
            leg[field] = value;

            markAsUnsaved();
            syncPlanState();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function updateJourneyFeeField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop || !stop.fees) return;
            const index = parseInt(element.dataset.feeIndex, 10);
            const fee = stop.fees[index];
            if (!fee) return;

            fee[element.dataset.feeField] = element.value;
            fee.estimated_cost = calculateFeeEstimate(fee);
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function addJourneyFee(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;
            if (!Array.isArray(stop.fees)) stop.fees = [];

            stop.fees.push({
                fee_type: 'storage',
                fee_name: '',
                rate: '',
                rate_unit: 'per_pallet',
                estimated_cost: 0
            });
            stop.is_saved = false;

            markAsUnsaved();
            renderJourneyPlan();
        }

        function removeJourneyFee(stopId, feeIndex) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop || !stop.fees) return;
            stop.fees.splice(feeIndex, 1);
            stop.is_saved = false;
            markAsUnsaved();
            renderJourneyPlan();
        }

        // Capture all current form values from the journey plan before re-rendering
        function captureJourneyFormState() {
            const container = document.getElementById('journeyFlow');
            if (!container) return;

            // Capture stop field values
            container.querySelectorAll('[data-stop-id][data-stop-field]').forEach(el => {
                const stopId = el.dataset.stopId;
                const field = el.dataset.stopField;
                const stop = workingState.stops.find(s => s.id == stopId);
                if (!stop) return;

                if (el.type === 'checkbox') {
                    stop[field] = el.checked ? 1 : 0;
                } else {
                    stop[field] = el.value;
                }
            });

            // Capture leg field values
            container.querySelectorAll('[data-leg-id][data-leg-field]').forEach(el => {
                const legId = el.dataset.legId;
                const field = el.dataset.legField;
                const leg = workingState.legs.find(l => l.id == legId);
                if (!leg) return;

                if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                    leg[field] = el.value === '' ? '' : parseFloat(el.value);
                } else {
                    leg[field] = el.value;
                }
            });

            // Capture fee field values
            container.querySelectorAll('[data-stop-id][data-fee-field]').forEach(el => {
                const stopId = el.dataset.stopId;
                const feeIndex = parseInt(el.dataset.feeIndex, 10);
                const field = el.dataset.feeField;
                const stop = workingState.stops.find(s => s.id == stopId);
                if (!stop || !stop.fees || !stop.fees[feeIndex]) return;

                stop.fees[feeIndex][field] = el.value;
            });
        }

        function removeJourneyStop(stopId) {
            captureJourneyFormState(); // Capture state before removing
            const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
            if (stopIndex <= 0 || stopIndex >= workingState.stops.length - 1) return;

            const prevStop = workingState.stops[stopIndex - 1];
            const nextStop = workingState.stops[stopIndex + 1];
            workingState.stops.splice(stopIndex, 1);

            workingState.legs = workingState.legs.filter(leg => leg.from_stop_id != stopId && leg.to_stop_id != stopId);
            if (prevStop && nextStop) {
                getLegForStops(prevStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function addJourneyStop(afterStopId, options = {}) {
            if (!canEdit) return;

            // IMPORTANT: Capture all current form values before re-rendering
            captureJourneyFormState();

            ensureStops();

            const stops = workingState.stops;
            let insertIndex;

            if (afterStopId) {
                insertIndex = stops.findIndex(s => s.id == afterStopId) + 1;
            } else {
                insertIndex = stops.findIndex(stop => stop.stop_type === 'destination');
            }

            if (insertIndex <= 0 || insertIndex >= stops.length) {
                insertIndex = stops.length - 1;
            }

            const newStop = {
                id: `warehouse_${Date.now()}`,
                stop_type: 'warehouse',
                location_name: '',
                location_address: '',
                latitude: null,
                longitude: null,
                fees: []
            };

            const prevStop = stops[insertIndex - 1];
            const nextStop = stops[insertIndex];

            stops.splice(insertIndex, 0, newStop);

            // Remove the old leg between prev and next
            workingState.legs = workingState.legs.filter(leg => !(leg.from_stop_id == prevStop?.id && leg.to_stop_id == nextStop?.id));

            // Create new legs
            if (prevStop) {
                getLegForStops(prevStop.id, newStop.id);
            }
            if (nextStop) {
                getLegForStops(newStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();

            // Scroll the new stop into the center of the container
            requestAnimationFrame(() => {
                centerJourneyStopInScroll(newStop.id);
            });

            if (options.openEditor) {
                setTimeout(() => openNodeEditor(newStop.id), 100);
            }

            return newStop;
        }

        function centerJourneyStopInScroll(stopId) {
            const stopsScroll = document.getElementById('journeyStopsScroll');
            if (!stopsScroll) return;

            const stopEl = stopsScroll.querySelector(`[data-stop-id="${stopId}"]`);
            if (!stopEl) return;

            const containerRect = stopsScroll.getBoundingClientRect();
            const stopRect = stopEl.getBoundingClientRect();
            const stopCenter = stopRect.left + stopRect.width / 2;
            const containerCenter = containerRect.left + containerRect.width / 2;
            const delta = stopCenter - containerCenter;

            stopsScroll.scrollTo({
                left: stopsScroll.scrollLeft + delta,
                behavior: 'smooth'
            });
        }

        function updateBadges() {
            const pallets = getTotalPallets();
            const stops = workingState.stops || [];
            const legs = workingState.legs || [];
            const modules = getTotalModules();

            // Module section badge
            const modulesBadge = document.getElementById('modulesBadge');
            if (modulesBadge) {
                modulesBadge.textContent = `${pallets.toLocaleString()} pallets`;
            }

            // Calculate totals
            const totalFreight = legs.reduce((sum, l) => sum + (parseFloat(l.total_freight_cost) || 0), 0);
            const totalWarehouseFees = stops.reduce((sum, s) => {
                return sum + (s.fees || []).reduce((fsum, f) => fsum + (parseFloat(f.estimated_cost) || 0), 0);
            }, 0);

            // Calculate milestone totals
            const milestoneEvents = collectMilestoneEvents();
            const totalMilestones = milestoneEvents.reduce((sum, e) => sum + (e.amount || 0), 0);
            const grandTotal = totalFreight + totalWarehouseFees + totalMilestones;

            // Calculate date range
            let earliestDate = null;
            let latestDate = null;

            legs.forEach(leg => {
                if (leg.start_date) {
                    const d = new Date(leg.start_date);
                    if (!isNaN(d) && (!earliestDate || d < earliestDate)) earliestDate = d;
                }
                if (leg.end_date) {
                    const d = new Date(leg.end_date);
                    if (!isNaN(d) && (!latestDate || d > latestDate)) latestDate = d;
                }
            });

            stops.forEach(stop => {
                if (stop.estimated_arrival_date) {
                    const d = new Date(stop.estimated_arrival_date);
                    if (!isNaN(d)) {
                        if (!earliestDate || d < earliestDate) earliestDate = d;
                        if (!latestDate || d > latestDate) latestDate = d;
                    }
                }
                if (stop.estimated_departure_date) {
                    const d = new Date(stop.estimated_departure_date);
                    if (!isNaN(d) && (!latestDate || d > latestDate)) latestDate = d;
                }
            });

            // Format dates
            const formatDate = (date) => {
                if (!date) return null;
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            };

            const formatDateFull = (date) => {
                if (!date) return null;
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };

            // Calculate duration in days
            let durationDays = 0;
            if (earliestDate && latestDate) {
                durationDays = Math.ceil((latestDate - earliestDate) / (1000 * 60 * 60 * 24));
            }

            // ==================== LOGISTICS SECTION (Simplified) ====================
            const logisticsRouteText = document.getElementById('logisticsRouteText');
            const logisticsTotalCost = document.getElementById('logisticsTotalCost');
            const totalLogisticsCost = totalFreight + totalWarehouseFees;

            if (logisticsRouteText) {
                if (stops.length === 0) {
                    logisticsRouteText.textContent = 'No route configured';
                } else {
                    const warehouseCount = stops.filter(s => !['origin', 'destination'].includes(s.stop_type)).length;
                    if (warehouseCount === 0) {
                        logisticsRouteText.textContent = 'Direct route';
                    } else {
                        logisticsRouteText.textContent = `${stops.length} stops, ${legs.length} legs`;
                    }
                }
            }

            if (logisticsTotalCost) {
                if (totalLogisticsCost > 0) {
                    logisticsTotalCost.textContent = '$' + formatCompactNumber(totalLogisticsCost) + ' total logistics';
                } else {
                    logisticsTotalCost.textContent = 'No costs yet';
                }
            }

            // ==================== TIMELINE SECTION (Simplified) ====================
            const timelineDateText = document.getElementById('timelineDateText');
            const timelineGrandTotal = document.getElementById('timelineGrandTotal');

            if (timelineDateText) {
                if (earliestDate && latestDate) {
                    const startStr = formatDateFull(earliestDate);
                    const endStr = formatDateFull(latestDate);
                    if (durationDays > 0) {
                        timelineDateText.textContent = `${startStr} → ${endStr} (${durationDays} days)`;
                    } else {
                        timelineDateText.textContent = `${startStr} → ${endStr}`;
                    }
                } else {
                    timelineDateText.textContent = 'No dates yet';
                }
            }

            if (timelineGrandTotal) {
                if (grandTotal > 0) {
                    timelineGrandTotal.textContent = '$' + formatCompactNumber(grandTotal) + ' projected total';
                } else {
                    timelineGrandTotal.textContent = 'No costs yet';
                }
            }

            // Update the cumulative displays at bottom of timeline section
            const totalFreightDisplay = document.getElementById('totalFreightDisplay');
            const totalWarehousingDisplay = document.getElementById('totalWarehousingDisplay');
            const totalMilestonesDisplay = document.getElementById('totalMilestonesDisplay');
            const grandTotalDisplay = document.getElementById('grandTotalDisplay');

            if (totalFreightDisplay) totalFreightDisplay.textContent = '$' + totalFreight.toLocaleString();
            if (totalWarehousingDisplay) totalWarehousingDisplay.textContent = '$' + totalWarehouseFees.toLocaleString();
            if (totalMilestonesDisplay) totalMilestonesDisplay.textContent = '$' + totalMilestones.toLocaleString();
            if (grandTotalDisplay) grandTotalDisplay.textContent = '$' + grandTotal.toLocaleString();
        }

        // Helper to format numbers compactly (e.g., 1.2K, 45K, 1.5M)
        function formatCompactNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
            }
            if (num >= 1000) {
                return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
            }
            return num.toLocaleString();
        }

        // Get total modules count
        function getTotalModules() {
            return (workingState.moduleAllocations || []).reduce((sum, a) => sum + (parseInt(a.quantity) || 0), 0);
        }

        // Keep renderDeliveryPlan for backwards compatibility
        function renderDeliveryPlan() {
            syncPlanState();

            const container = document.getElementById('deliveryPlan');
            const emptyState = document.getElementById('deliveryPlanEmpty');
            if (!container) return;

            const disabledAttr = canEdit ? '' : 'disabled';
            const stops = workingState.stops || [];
            const segments = [];
            for (let i = 0; i < stops.length - 1; i++) {
                const fromStop = stops[i];
                const toStop = stops[i + 1];
                if (!fromStop || !toStop) continue;

                const leg = getLegForStops(fromStop.id, toStop.id);
                const milestone = getMilestoneForStop(toStop);
                const isDestination = toStop.stop_type === 'destination';
                const trucks = parseInt(leg.trucks_required, 10) || getTotalTrucks();
                const totalFreight = leg.total_freight_cost || 0;
                const endDate = leg.end_date || '';
                const feeRows = (toStop.fees || []).map((fee, feeIndex) => {
                    return `
                        <div class="fee-row" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}">
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Fee Type</label>
                                <select class="delivery-select" data-fee-field="fee_type" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="receiving" ${fee.fee_type === 'receiving' ? 'selected' : ''}>On Entry</option>
                                    <option value="outbound" ${fee.fee_type === 'outbound' ? 'selected' : ''}>On Exit</option>
                                    <option value="storage" ${fee.fee_type === 'storage' ? 'selected' : ''}>Monthly</option>
                                    <option value="one_time" ${fee.fee_type === 'one_time' ? 'selected' : ''}>One Time</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Description</label>
                                <input type="text" class="delivery-input" data-fee-field="fee_name" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" value="${escapeHtml(fee.fee_name || '')}" placeholder="Fee description" ${disabledAttr}>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Rate</label>
                                <input type="number" class="delivery-input" data-fee-field="rate" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" value="${fee.rate || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                            </div>
                            <div>
                                <label class="form-label" style="font-size: 0.8em;">Per</label>
                                <select class="delivery-select" data-fee-field="rate_unit" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}" ${disabledAttr}>
                                    <option value="per_pallet" ${fee.rate_unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                                    <option value="per_truck" ${fee.rate_unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                                    <option value="per_sqft" ${fee.rate_unit === 'per_sqft' ? 'selected' : ''}>Per SQFT</option>
                                    <option value="flat" ${fee.rate_unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                                </select>
                            </div>
                            ${canEdit ? `
                            <button type="button" class="btn btn-sm btn-danger" data-action="remove-fee" data-stop-id="${toStop.id}" data-fee-index="${feeIndex}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                            ` : ''}
                        </div>
                    `;
                }).join('');

                const showActions = canEdit && !isDestination && toStop.id === stops[stops.length - 2]?.id;
                const removeButton = canEdit && !isDestination
                    ? `<button type="button" class="btn btn-sm btn-danger" data-action="remove-stop" data-stop-id="${toStop.id}">Remove Warehouse</button>`
                    : '';

                const destinationTypeInput = isDestination
                    ? `<input class="delivery-input" value="Project Site" readonly>`
                    : `
                        <select class="delivery-select" data-stop-id="${toStop.id}" data-stop-field="stop_type" ${disabledAttr}>
                            <option value="warehouse" ${toStop.stop_type === 'warehouse' ? 'selected' : ''}>Warehouse</option>
                            <option value="port" ${toStop.stop_type === 'port' ? 'selected' : ''}>Port</option>
                            <option value="customs" ${toStop.stop_type === 'customs' ? 'selected' : ''}>Customs Facility</option>
                        </select>
                    `;

                const locationNameInput = isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(toStop.location_name || 'Project Site')}" readonly>`
                    : `<input class="delivery-input" data-stop-id="${toStop.id}" data-stop-field="location_name" value="${escapeHtml(toStop.location_name || '')}" placeholder="Warehouse name" ${disabledAttr}>`;

                const locationAddressInput = isDestination
                    ? `<input class="delivery-input" value="${escapeHtml(toStop.location_address || '')}" readonly>`
                    : `<input class="delivery-input" data-stop-id="${toStop.id}" data-stop-field="location_address" value="${escapeHtml(toStop.location_address || '')}" placeholder="Street, city, state" ${disabledAttr}>`;

                segments.push(`
                    <div class="delivery-step" data-from-stop-id="${fromStop.id}" data-to-stop-id="${toStop.id}">
                        <div class="delivery-step-header">
                            <div class="delivery-step-title">Delivery ${i + 1}</div>
                            <div class="delivery-badge">${milestone.label}</div>
                            ${removeButton}
                        </div>
                        <div class="delivery-grid">
                            <div class="delivery-field">
                                <label>From</label>
                                <div class="delivery-location">
                                    <strong>${escapeHtml(fromStop.location_name || 'Manufacturer')}</strong>
                                    <small>${escapeHtml(fromStop.location_address || 'Set from module manufacturer')}</small>
                                </div>
                            </div>
                            <div class="delivery-field">
                                <label>Destination Type</label>
                                ${destinationTypeInput}
                            </div>
                            <div class="delivery-field">
                                <label>Destination Name</label>
                                ${locationNameInput}
                            </div>
                            <div class="delivery-field">
                                <label>Destination Address</label>
                                ${locationAddressInput}
                            </div>
                        </div>
                        <div class="delivery-schedule">
                            <div class="delivery-grid">
                                <div class="delivery-field">
                                    <label>Transport Mode</label>
                                    <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="transport_mode" ${disabledAttr}>
                                        <option value="truck" ${leg.transport_mode === 'truck' ? 'selected' : ''}>Truck</option>
                                        <option value="ocean" ${leg.transport_mode === 'ocean' ? 'selected' : ''}>Ocean</option>
                                        <option value="rail" ${leg.transport_mode === 'rail' ? 'selected' : ''}>Rail</option>
                                        <option value="air" ${leg.transport_mode === 'air' ? 'selected' : ''}>Air</option>
                                    </select>
                                </div>
                                <div class="delivery-field">
                                    <label>Start Date</label>
                                    <input type="text" class="delivery-input flatpickr-date" data-leg-id="${leg.id}" data-leg-field="start_date" value="${leg.start_date || ''}" placeholder="Select date" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Cadence</label>
                                    <div class="delivery-grid" style="grid-template-columns: 1fr 1fr; gap: 10px;">
                                        <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="delivery_rate" value="${leg.delivery_rate || ''}" placeholder="Rate" min="0" step="0.1" ${disabledAttr}>
                                        <select class="delivery-select" data-leg-id="${leg.id}" data-leg-field="delivery_rate_unit" ${disabledAttr}>
                                            <option value="per_week" ${leg.delivery_rate_unit === 'per_week' ? 'selected' : ''}>Per Week</option>
                                            <option value="per_day" ${leg.delivery_rate_unit === 'per_day' ? 'selected' : ''}>Per Day</option>
                                            <option value="per_month" ${leg.delivery_rate_unit === 'per_month' ? 'selected' : ''}>Per Month</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="delivery-field">
                                    <label>End Date (Auto)</label>
                                    <input type="text" class="delivery-input" value="${endDate}" readonly>
                                </div>
                            </div>
                            <div class="delivery-grid" style="margin-top: 16px;">
                                <div class="delivery-field">
                                    <label>Trucks Required</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="trucks_required" value="${leg.trucks_required || ''}" placeholder="${trucks}" min="1" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Freight Per Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="freight_cost_per_truck" value="${leg.freight_cost_per_truck || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Accessorial Per Truck</label>
                                    <input type="number" class="delivery-input" data-leg-id="${leg.id}" data-leg-field="accessorial_cost_per_truck" value="${leg.accessorial_cost_per_truck || ''}" placeholder="$0" step="0.01" min="0" ${disabledAttr}>
                                </div>
                                <div class="delivery-field">
                                    <label>Total Freight</label>
                                    <input type="text" class="delivery-input" value="$${totalFreight.toFixed(2)}" readonly>
                                </div>
                            </div>
                            <div class="delivery-summary">
                                <span>Estimated deliveries: ${trucks}</span>
                                <span class="delivery-milestone">${milestone.label}</span>
                            </div>
                        </div>
                        ${!isDestination ? `
                            <div class="delivery-fees">
                                <div class="delivery-fees-header">
                                    <strong>Warehouse Fees</strong>
                                    ${canEdit ? `<button type="button" class="btn btn-sm btn-secondary" data-action="add-fee" data-stop-id="${toStop.id}">Add Fee</button>` : ''}
                                </div>
                                ${feeRows || '<p style="margin:0;color:#6c757d;">No fees added yet.</p>'}
                                <div class="delivery-field" style="margin-top: 12px;">
                                    <label style="display:flex; align-items:center; gap:10px;">
                                        <input type="checkbox" data-stop-id="${toStop.id}" data-stop-field="is_customs_clearance" ${toStop.is_customs_clearance ? 'checked' : ''} ${disabledAttr}>
                                        <span>Customs clearance at this stop</span>
                                    </label>
                                </div>
                            </div>
                        ` : ''}
                        ${showActions ? `
                            <div class="delivery-actions">
                                <button type="button" class="btn btn-secondary" data-action="add-warehouse" data-stop-id="${toStop.id}">Add another warehouse delivery</button>
                                <span style="color:#6c757d; font-size:0.85em;">Next leg delivers to project site.</span>
                            </div>
                        ` : ''}
                    </div>
                `);
            }

            container.innerHTML = segments.join('');
            if (emptyState) {
                emptyState.style.display = segments.length ? 'none' : 'block';
            }

            initializeDatePickers();
            bindDeliveryPlanListeners();
        }

        function bindDeliveryPlanListeners() {
            const container = document.getElementById('deliveryPlan');
            if (!container) return;

            container.onchange = function(event) {
                const target = event.target;
                if (target.dataset.stopField) {
                    updateStopField(target);
                } else if (target.dataset.legField) {
                    updateLegField(target);
                } else if (target.dataset.feeField) {
                    updateFeeField(target);
                }
            };

            container.onclick = function(event) {
                const actionButton = event.target.closest('[data-action]');
                if (!actionButton) return;
                const action = actionButton.dataset.action;
                if (action === 'add-fee') {
                    addPlanFee(actionButton.dataset.stopId);
                } else if (action === 'remove-fee') {
                    removePlanFee(actionButton.dataset.stopId, parseInt(actionButton.dataset.feeIndex, 10));
                } else if (action === 'remove-stop') {
                    removeWarehouseStop(actionButton.dataset.stopId);
                } else if (action === 'add-warehouse') {
                    addWarehouseDelivery();
                }
            };
        }

        function updateStopField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop) return;

            const field = element.dataset.stopField;
            const value = element.type === 'checkbox' ? (element.checked ? 1 : 0) : element.value;
            stop[field] = value;

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function updateLegField(element) {
            const leg = workingState.legs.find(l => l.id == element.dataset.legId);
            if (!leg) return;

            const field = element.dataset.legField;
            let value = element.value;
            if (['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'trucks_required'].includes(field)) {
                value = value === '' ? '' : parseFloat(value);
            }
            leg[field] = value;

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function updateFeeField(element) {
            const stop = workingState.stops.find(s => s.id == element.dataset.stopId);
            if (!stop || !stop.fees) return;
            const index = parseInt(element.dataset.feeIndex, 10);
            const fee = stop.fees[index];
            if (!fee) return;

            fee[element.dataset.feeField] = element.value;
            fee.estimated_cost = calculateFeeEstimate(fee);

            markAsUnsaved();
            renderJourneyPlan();
            updateTimelineChart();
        }

        function addPlanFee(stopId) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop) return;
            if (!Array.isArray(stop.fees)) stop.fees = [];

            stop.fees.push({
                fee_type: 'storage',
                fee_name: '',
                rate: '',
                rate_unit: 'per_pallet',
                estimated_cost: 0
            });

            markAsUnsaved();
            renderJourneyPlan();
        }

        function removePlanFee(stopId, feeIndex) {
            const stop = workingState.stops.find(s => s.id == stopId);
            if (!stop || !stop.fees) return;
            stop.fees.splice(feeIndex, 1);
            markAsUnsaved();
            renderJourneyPlan();
        }

        function removeWarehouseStop(stopId) {
            const stopIndex = workingState.stops.findIndex(s => s.id == stopId);
            if (stopIndex <= 0 || stopIndex >= workingState.stops.length - 1) return;

            const prevStop = workingState.stops[stopIndex - 1];
            const nextStop = workingState.stops[stopIndex + 1];
            workingState.stops.splice(stopIndex, 1);

            workingState.legs = workingState.legs.filter(leg => leg.from_stop_id != stopId && leg.to_stop_id != stopId);
            if (prevStop && nextStop) {
                getLegForStops(prevStop.id, nextStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        function addWarehouseDelivery() {
            if (!canEdit) return;
            ensureStops();

            const stops = workingState.stops;
            const destinationIndex = stops.findIndex(stop => stop.stop_type === 'destination');
            if (destinationIndex === -1) return;

            const newStop = {
                id: `warehouse_${Date.now()}`,
                stop_type: 'warehouse',
                location_name: '',
                location_address: '',
                fees: []
            };

            stops.splice(destinationIndex, 0, newStop);

            const prevStop = stops[destinationIndex - 1];
            const destinationStop = stops[destinationIndex + 1];

            workingState.legs = workingState.legs.filter(leg => !(leg.from_stop_id == prevStop?.id && leg.to_stop_id == destinationStop?.id));

            if (prevStop) {
                getLegForStops(prevStop.id, newStop.id);
            }
            if (destinationStop) {
                getLegForStops(newStop.id, destinationStop.id);
            }

            markAsUnsaved();
            renderJourneyPlan();
            updateMapFromState();
            updateTimelineChart();
        }

        // ==================== UTILITY FUNCTIONS ====================
        function getTotalPallets() {
            return workingState.moduleAllocations.reduce((sum, a) => sum + (a.pallets || 0), 0);
        }

        function getTotalTrucks() {
            // Use project configuration for accurate truck count
            const palletsPerTruck = projectInfo.palletsPerTruck || 20;
            const pallets = getTotalPallets();

            // If we have the pre-calculated total from PHP, use it
            if (projectInfo.totalTrucks > 0) {
                return Math.ceil(projectInfo.totalTrucks);
            }

            return Math.ceil(pallets / palletsPerTruck) || 1;
        }

        function recalculateCosts() {
            showToast('Costs will be recalculated when you save', 'info');
        }

        function updatePOExecutionDate(dateValue) {
            workingState.poExecutionDate = dateValue;
            markAsUnsaved();
            updateTimelineChart();
            showToast('PO Execution date updated. Remember to save the projection!', 'success');
        }

        function updateModuleAllocationPoDate(allocationId, dateValue) {
            const allocationKey = String(allocationId);
            const allocation = workingState.moduleAllocations.find(item => String(item.id ?? item.module_id) === allocationKey);
            if (!allocation) {
                return;
            }

            const normalizedDate = dateValue || '';
            if ((allocation.po_execution_date || '') === normalizedDate) {
                return;
            }

            allocation.po_execution_date = normalizedDate;
            markAsUnsaved();
        }

        // ==================== UI HELPERS ====================
        function showLoading(text = 'Loading...') {
            document.querySelector('.loading-text').textContent = text;
            document.getElementById('loadingOverlay').classList.add('active');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }

        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const iconSvg = type === 'success'
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
                : type === 'error'
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
                : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';

            toast.innerHTML = `
                <div class="toast-icon">${iconSvg}</div>
                <div class="toast-message">${message}</div>
            `;

            container.appendChild(toast);

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.style.animation = 'toastSlideIn 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStopEditorModal();
                closeLegEditorModal();
                closeModuleSelectorModal();
                closeWattageQuantityModal();
            }
        });

        // ==================== GEOCODING ====================
        function geocodeAddress(address, callback) {
            if (typeof google === 'undefined' || !google.maps || !google.maps.Geocoder) {
                console.log('Google Geocoder not available');
                callback(null);
                return;
            }

            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: address }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    const location = results[0].geometry.location;
                    callback({ lat: location.lat(), lng: location.lng() });
                } else {
                    console.log('Geocode failed for:', address, status);
                    callback(null);
                }
            });
        }

        // ==================== TIMELINE CHART TABS ====================
        function switchTimelineTab(tabId) {
            // Update tab buttons
            document.querySelectorAll('.timeline-tab').forEach(tab => {
                tab.classList.toggle('active', tab.dataset.tab === tabId);
            });

            // Update panels
            document.querySelectorAll('.timeline-panel').forEach(panel => {
                panel.classList.toggle('active', panel.id === tabId + '-panel');
            });

            // If switching to line chart, render it and weekly projections
            if (tabId === 'line-chart') {
                renderMonthlyForecastChart();
                renderWeeklyProjectionsTable();
            }
        }

        // ==================== MONTHLY FORECAST LINE CHART ====================
        let monthlyChartInstance = null;

        function renderMonthlyForecastChart() {
            const ctx = document.getElementById('monthlyForecastChart');
            if (!ctx) return;

            // Destroy existing chart
            if (monthlyChartInstance) {
                monthlyChartInstance.destroy();
            }

            // Collect monthly data
            const monthlyData = collectMonthlyData();

            if (monthlyData.labels.length === 0) {
                ctx.parentElement.innerHTML = '<div style="text-align: center; padding: 60px; color: #6c757d;">Add dates to your stops and legs to see the monthly forecast.</div>';
                return;
            }

            monthlyChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: monthlyData.labels,
                    datasets: [
                        {
                            label: 'Freight Costs',
                            data: monthlyData.freight,
                            borderColor: '#488C9A',
                            backgroundColor: 'rgba(72, 140, 154, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Warehousing Costs',
                            data: monthlyData.warehousing,
                            borderColor: '#E07F3A',
                            backgroundColor: 'rgba(224, 127, 58, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Milestone Payments',
                            data: monthlyData.milestones,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'Cumulative Total',
                            data: monthlyData.cumulative,
                            borderColor: '#293E4C',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            borderDash: [5, 5],
                            fill: false,
                            tension: 0.3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': $' + context.raw.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }

        function collectMonthlyData() {
            const monthlyBuckets = {};
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const addedMilestones = new Set();

            // Collect freight costs by month
            workingState.legs.forEach(leg => {
                if (leg.start_date) {
                    const monthKey = leg.start_date.substring(0, 7); // YYYY-MM
                    if (!monthlyBuckets[monthKey]) {
                        monthlyBuckets[monthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    monthlyBuckets[monthKey].freight += leg.total_freight_cost || 0;

                    // Add milestone payment if triggered
                    if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                        monthlyBuckets[monthKey].milestones += milestoneTotals[leg.triggers_milestone].amount || 0;
                        addedMilestones.add(leg.triggers_milestone);
                    }
                }
            });

            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const eventDate = event.date || new Date().toISOString().split('T')[0];
                    const monthKey = eventDate.substring(0, 7);
                    if (!monthlyBuckets[monthKey]) {
                        monthlyBuckets[monthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    monthlyBuckets[monthKey].milestones += event.amount || 0;
                });

            // Collect warehousing costs by month
            workingState.stops.forEach((stop, stopIndex) => {
                if (stop.estimated_arrival_date && stop.fees && stop.fees.length > 0) {
                    const arrivalDate = new Date(stop.estimated_arrival_date);
                    if (isNaN(arrivalDate.getTime())) return; // skip invalid dates

                    stop.fees.forEach(fee => {
                        const cost = fee.estimated_cost || 0;
                        if (cost <= 0) return;

                        if (fee.fee_type === 'storage' || fee.trigger === 'monthly') {
                            // Monthly storage fees: distribute to each month starting from arrival month
                            let departureDate;
                            if (stop.estimated_departure_date && new Date(stop.estimated_departure_date) > arrivalDate) {
                                departureDate = new Date(stop.estimated_departure_date);
                            } else {
                                const nextStop = workingState.stops[stopIndex + 1];
                                departureDate = (nextStop?.estimated_arrival_date && new Date(nextStop.estimated_arrival_date) > arrivalDate)
                                    ? new Date(nextStop.estimated_arrival_date)
                                    : new Date(arrivalDate.getTime() + 90 * 24 * 60 * 60 * 1000);
                            }

                            const monthsInStorage = Math.max(1, Math.ceil((departureDate - arrivalDate) / (30 * 24 * 60 * 60 * 1000)));
                            const monthlyFee = cost / monthsInStorage;

                            // Use 1st of month as the bucket key
                            const startMonth = new Date(arrivalDate.getFullYear(), arrivalDate.getMonth(), 1);
                            for (let i = 0; i < monthsInStorage; i++) {
                                const storageMonth = new Date(startMonth);
                                storageMonth.setMonth(storageMonth.getMonth() + i);
                                const storageMonthKey = storageMonth.toISOString().substring(0, 7);
                                if (!monthlyBuckets[storageMonthKey]) {
                                    monthlyBuckets[storageMonthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                                }
                                monthlyBuckets[storageMonthKey].warehousing += monthlyFee;
                            }
                        } else {
                            // In/out/handling fees: place at arrival month (in/receiving) or departure month (out/outbound)
                            let feeMonthKey = stop.estimated_arrival_date.substring(0, 7);
                            if (fee.fee_type === 'outbound' || fee.fee_type === 'out') {
                                // Try to get departure date from stop, or fall back to next leg/stop
                                let departureDate = null;
                                let departureDateStr = null;
                                if (stop.estimated_departure_date) {
                                    const depDate = new Date(stop.estimated_departure_date);
                                    if (!isNaN(depDate.getTime())) {
                                        departureDate = depDate;
                                        departureDateStr = stop.estimated_departure_date;
                                    }
                                }
                                // Fallback: use the outgoing leg's start date or next stop's arrival
                                if (!departureDate) {
                                    const outgoingLeg = workingState.legs.find(l => l.from_stop_id == stop.id);
                                    if (outgoingLeg?.start_date) {
                                        const legDate = new Date(outgoingLeg.start_date);
                                        if (!isNaN(legDate.getTime())) {
                                            departureDate = legDate;
                                            departureDateStr = outgoingLeg.start_date;
                                        }
                                    }
                                }
                                if (!departureDate) {
                                    const nextStop = workingState.stops[stopIndex + 1];
                                    if (nextStop?.estimated_arrival_date) {
                                        const nextDate = new Date(nextStop.estimated_arrival_date);
                                        if (!isNaN(nextDate.getTime())) {
                                            departureDate = nextDate;
                                            departureDateStr = nextStop.estimated_arrival_date;
                                        }
                                    }
                                }
                                if (departureDate && departureDateStr && departureDate > arrivalDate) {
                                    feeMonthKey = departureDateStr.substring(0, 7);
                                }
                            }
                            if (!monthlyBuckets[feeMonthKey]) {
                                monthlyBuckets[feeMonthKey] = { freight: 0, warehousing: 0, milestones: 0 };
                            }
                            monthlyBuckets[feeMonthKey].warehousing += cost;
                        }
                    });
                }
            });

            // Sort months and prepare arrays
            const sortedMonths = Object.keys(monthlyBuckets).sort();

            const labels = sortedMonths.map(m => {
                const date = new Date(m + '-01');
                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
            });

            let cumulativeTotal = 0;
            const freight = [];
            const warehousing = [];
            const milestones = [];
            const cumulative = [];

            sortedMonths.forEach(month => {
                const data = monthlyBuckets[month];
                freight.push(data.freight);
                warehousing.push(data.warehousing);
                milestones.push(data.milestones);
                cumulativeTotal += data.freight + data.warehousing + data.milestones;
                cumulative.push(cumulativeTotal);
            });

            return { labels, freight, warehousing, milestones, cumulative };
        }

        // ==================== WEEKLY PROJECTIONS TABLE ====================
        function renderWeeklyProjectionsTable() {
            const tbody = document.getElementById('weeklyProjectionsBody');
            const emptyState = document.getElementById('weeklyEmptyState');
            const tableWrapper = document.querySelector('.weekly-table-wrapper');

            if (!tbody) return;

            // Collect weekly data
            const weeklyData = collectWeeklyData();

            if (weeklyData.length === 0) {
                if (tableWrapper) tableWrapper.style.display = 'none';
                if (emptyState) emptyState.style.display = 'block';
                return;
            }

            if (tableWrapper) tableWrapper.style.display = 'block';
            if (emptyState) emptyState.style.display = 'none';

            let html = '';
            let cumulativeTotal = 0;
            const totals = { freight: 0, warehousing: 0, milestones: 0 };

            const formatRange = (startValue, endValue) => {
                if (!startValue || !endValue) return '-';
                const start = startValue instanceof Date ? startValue : new Date(startValue);
                const end = endValue instanceof Date ? endValue : new Date(endValue);
                if (isNaN(start.getTime()) || isNaN(end.getTime())) return '-';
                const startFormatted = start.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const endFormatted = end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                return `${startFormatted} - ${endFormatted}`;
            };

            weeklyData.forEach((week, index) => {
                const weeklyTotal = week.freight + week.warehousing + week.milestones;
                cumulativeTotal += weeklyTotal;
                totals.freight += week.freight;
                totals.warehousing += week.warehousing;
                totals.milestones += week.milestones;

                html += `
                    <tr>
                        <td class="week-number">Week ${index + 1}</td>
                        <td class="date-range">${week.dateRange}</td>
                        <td class="amount freight">${formatCurrency(week.freight)}</td>
                        <td class="amount warehousing">${formatCurrency(week.warehousing)}</td>
                        <td class="amount milestone">${formatCurrency(week.milestones)}</td>
                        <td class="weekly-total">${formatCurrency(weeklyTotal)}</td>
                        <td class="cumulative">${formatCurrency(cumulativeTotal)}</td>
                    </tr>
                `;
            });

            const overallRange = weeklyData.length
                ? formatRange(weeklyData[0].weekStartDate, weeklyData[weeklyData.length - 1].weekEndDate)
                : '-';
            const grandTotal = totals.freight + totals.warehousing + totals.milestones;

            html += `
                <tr class="totals-row">
                    <td>Total</td>
                    <td class="date-range">${overallRange}</td>
                    <td class="amount freight">${formatCurrency(totals.freight)}</td>
                    <td class="amount warehousing">${formatCurrency(totals.warehousing)}</td>
                    <td class="amount milestone">${formatCurrency(totals.milestones)}</td>
                    <td class="weekly-total">${formatCurrency(grandTotal)}</td>
                    <td class="cumulative">${formatCurrency(grandTotal)}</td>
                </tr>
            `;

            tbody.innerHTML = html;
        }

        function collectWeeklyData() {
            const weeklyBuckets = {};
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const addedMilestones = new Set();

            // Helper to get week key from date
            function getWeekKey(dateStr) {
                const date = new Date(dateStr);
                const startOfWeek = new Date(date);
                startOfWeek.setDate(date.getDate() - date.getDay()); // Start of week (Sunday)
                return startOfWeek.toISOString().split('T')[0];
            }

            // Helper to get week end date
            function getWeekEnd(weekStartStr) {
                const start = new Date(weekStartStr);
                const end = new Date(start);
                end.setDate(start.getDate() + 6);
                return end;
            }

            // Collect freight costs by week
            workingState.legs.forEach(leg => {
                if (leg.start_date) {
                    const weekKey = getWeekKey(leg.start_date);
                    if (!weeklyBuckets[weekKey]) {
                        weeklyBuckets[weekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    weeklyBuckets[weekKey].freight += leg.total_freight_cost || 0;

                    // Add milestone payment if triggered
                    if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                        weeklyBuckets[weekKey].milestones += milestoneTotals[leg.triggers_milestone].amount || 0;
                        addedMilestones.add(leg.triggers_milestone);
                    }
                }
            });

            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const eventDate = event.date || new Date().toISOString().split('T')[0];
                    const weekKey = getWeekKey(eventDate);
                    if (!weeklyBuckets[weekKey]) {
                        weeklyBuckets[weekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                    }
                    weeklyBuckets[weekKey].milestones += event.amount || 0;
                });

            // Collect warehousing costs by week
            workingState.stops.forEach((stop, stopIndex) => {
                if (stop.estimated_arrival_date && stop.fees && stop.fees.length > 0) {
                    const arrivalDate = new Date(stop.estimated_arrival_date);
                    if (isNaN(arrivalDate.getTime())) return; // skip invalid dates

                    stop.fees.forEach(fee => {
                        const cost = fee.estimated_cost || 0;
                        if (cost <= 0) return;

                        if (fee.fee_type === 'storage' || fee.trigger === 'monthly') {
                            // Monthly storage fees: place on week containing 1st of each month
                            let departureDate;
                            if (stop.estimated_departure_date && new Date(stop.estimated_departure_date) > arrivalDate) {
                                departureDate = new Date(stop.estimated_departure_date);
                            } else {
                                const nextStop = workingState.stops[stopIndex + 1];
                                departureDate = (nextStop?.estimated_arrival_date && new Date(nextStop.estimated_arrival_date) > arrivalDate)
                                    ? new Date(nextStop.estimated_arrival_date)
                                    : new Date(arrivalDate.getTime() + 90 * 24 * 60 * 60 * 1000);
                            }

                            const monthsInStorage = Math.max(1, Math.ceil((departureDate - arrivalDate) / (30 * 24 * 60 * 60 * 1000)));
                            const monthlyFee = cost / monthsInStorage;

                            // Place each monthly payment on the 1st of the month
                            const startMonth = new Date(arrivalDate.getFullYear(), arrivalDate.getMonth(), 1);
                            for (let i = 0; i < monthsInStorage; i++) {
                                const monthFirst = new Date(startMonth);
                                monthFirst.setMonth(monthFirst.getMonth() + i);
                                const monthFirstKey = getWeekKey(monthFirst.toISOString().split('T')[0]);
                                if (!weeklyBuckets[monthFirstKey]) {
                                    weeklyBuckets[monthFirstKey] = { freight: 0, warehousing: 0, milestones: 0 };
                                }
                                weeklyBuckets[monthFirstKey].warehousing += monthlyFee;
                            }
                        } else {
                            // In/out/handling fees: place at arrival (in/receiving) or departure (out/outbound)
                            let feeDate = arrivalDate;
                            // Outbound fees should be placed at departure date
                            if (fee.fee_type === 'outbound' || fee.fee_type === 'out') {
                                // Try to get departure date from stop, or fall back to next leg/stop
                                let departureDate = null;
                                if (stop.estimated_departure_date) {
                                    const depDate = new Date(stop.estimated_departure_date);
                                    if (!isNaN(depDate.getTime())) departureDate = depDate;
                                }
                                // Fallback: use the outgoing leg's start date or next stop's arrival
                                if (!departureDate) {
                                    const outgoingLeg = workingState.legs.find(l => l.from_stop_id == stop.id);
                                    if (outgoingLeg?.start_date) {
                                        const legDate = new Date(outgoingLeg.start_date);
                                        if (!isNaN(legDate.getTime())) departureDate = legDate;
                                    }
                                }
                                if (!departureDate) {
                                    const nextStop = workingState.stops[stopIndex + 1];
                                    if (nextStop?.estimated_arrival_date) {
                                        const nextDate = new Date(nextStop.estimated_arrival_date);
                                        if (!isNaN(nextDate.getTime())) departureDate = nextDate;
                                    }
                                }
                                if (departureDate && departureDate > arrivalDate) {
                                    feeDate = departureDate;
                                }
                            }
                            const feeWeekKey = getWeekKey(feeDate.toISOString().split('T')[0]);
                            if (!weeklyBuckets[feeWeekKey]) {
                                weeklyBuckets[feeWeekKey] = { freight: 0, warehousing: 0, milestones: 0 };
                            }
                            weeklyBuckets[feeWeekKey].warehousing += cost;
                        }
                    });
                }
            });

            // Sort weeks and prepare array with date ranges
            const sortedWeeks = Object.keys(weeklyBuckets).sort();

            return sortedWeeks.map(weekStart => {
                const weekStartDate = new Date(weekStart);
                const weekEndDate = getWeekEnd(weekStart);
                const startFormatted = weekStartDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                const endFormatted = weekEndDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                return {
                    weekStartDate,
                    weekEndDate,
                    dateRange: `${startFormatted} - ${endFormatted}`,
                    freight: weeklyBuckets[weekStart].freight,
                    warehousing: weeklyBuckets[weekStart].warehousing,
                    milestones: weeklyBuckets[weekStart].milestones
                };
            });
        }

        function formatCurrency(amount) {
            if (amount === 0) return '-';
            return '$' + amount.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }

        // ==================== WAREHOUSE CARD COLLAPSE ====================
        function toggleWarehouseCard(stopId) {
            const card = document.querySelector(`.warehouse-stop-card[data-stop-id="${stopId}"]`);
            const stop = workingState.stops.find(item => item.id == stopId);
            if (card) {
                card.classList.toggle('collapsed');
            }
            if (stop) {
                stop.is_collapsed = card ? card.classList.contains('collapsed') : !stop.is_collapsed;
            }
        }

        // ==================== GOOGLE MAPS ====================
        let map = null;
        let mapMarkers = [];
        let mapPolylines = [];

        function initializeMap() {
            if (typeof google === 'undefined' || !google.maps) {
                console.log('Google Maps not available');
                return;
            }

            const mapContainer = document.getElementById('routeMap');
            if (!mapContainer) return;

            map = new google.maps.Map(mapContainer, {
                zoom: 2,
                center: { lat: 20, lng: 0 },
                mapTypeId: 'roadmap',
                styles: [
                    { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#dce8ec' }] },
                    { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#9eaaaf' }] },
                    { featureType: 'landscape', elementType: 'geometry', stylers: [{ color: '#f0f4f5' }] },
                    { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
                    { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#e4ecef' }] },
                    { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#f5f0eb' }] },
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'off' }] },
                    { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: '#c5d5d9' }] },
                    { featureType: 'administrative.land_parcel', stylers: [{ visibility: 'off' }] },
                    { featureType: 'administrative.neighborhood', stylers: [{ visibility: 'off' }] }
                ],
                disableDefaultUI: false,
                zoomControl: true,
                mapTypeControl: true,
                scaleControl: true,
                streetViewControl: false,
                rotateControl: false,
                fullscreenControl: true
            });

            updateMapFromState();
        }

        function getMarkerSvg(stopType, stepNumber) {
            const iconSize = 44;
            let fillColor, label;

            switch (stopType) {
                case 'origin':
                    fillColor = '#28a745';
                    label = 'O';
                    break;
                case 'destination':
                    fillColor = '#dc3545';
                    label = 'D';
                    break;
                case 'port':
                    fillColor = '#17a2b8';
                    label = 'P';
                    break;
                default:
                    fillColor = '#E07F3A';
                    label = stepNumber.toString();
            }

            return {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.25"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="22" cy="22" r="18" fill="' + fillColor + '" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="22" y="28" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial, sans-serif">' + label + '</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize / 2, iconSize / 2)
            };
        }

        function buildInfoWindowContent(stop, stopIndex, totalStops) {
            const typeColors = {
                'origin': { gradient: 'linear-gradient(135deg, #28a745 0%, #1e8449 100%)', bg: '#e8f5e8', icon: 'O' },
                'destination': { gradient: 'linear-gradient(135deg, #dc3545 0%, #b02a37 100%)', bg: '#fde8e8', icon: 'D' },
                'warehouse': { gradient: 'linear-gradient(135deg, #E07F3A 0%, #c76a2e 100%)', bg: '#fef4ed', icon: 'W' },
                'port': { gradient: 'linear-gradient(135deg, #17a2b8 0%, #138496 100%)', bg: '#e3f4f7', icon: 'P' },
                'customs': { gradient: 'linear-gradient(135deg, #6f42c1 0%, #5a33a1 100%)', bg: '#f0eaf9', icon: 'C' }
            };

            const config = typeColors[stop.stop_type] || typeColors.warehouse;
            const typeLabel = stop.stop_type === 'origin' ? 'Origin / Manufacturer' :
                              stop.stop_type === 'destination' ? 'Final Destination' :
                              stop.stop_type === 'port' ? 'Port' :
                              stop.stop_type === 'customs' ? 'Customs Facility' : 'Warehouse';

            // Calculate fees for this stop
            const totalFees = (stop.fees || []).reduce((sum, f) => sum + (parseFloat(f.estimated_cost) || 0), 0);
            const feeCount = (stop.fees || []).length;

            // Calculate arrival date RANGE instead of single date
            let arrivalRangeHtml = '';
            const formatDateShort = (date) => {
                if (!date) return null;
                const d = new Date(date);
                if (isNaN(d)) return null;
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            };

            // Get arrival start from estimated_arrival_date
            const arrivalStart = stop.estimated_arrival_date ? formatDateShort(stop.estimated_arrival_date) : null;

            // Get arrival end from estimated_departure_date or calculate from delivery cadence
            let arrivalEnd = null;
            if (stop.estimated_departure_date) {
                arrivalEnd = formatDateShort(stop.estimated_departure_date);
            } else if (stop.estimated_arrival_date) {
                // For destination, calculate end date based on delivery duration
                // Use leg info to determine how long deliveries take
                const legs = workingState.legs || [];
                const incomingLeg = legs.find(l => l.to_stop_id == stop.id);

                if (incomingLeg && incomingLeg.delivery_rate > 0) {
                    const totalPallets = getTotalPallets();
                    const palletsPerTruck = projectInfo.palletsPerTruck || 20;
                    const trucks = Math.ceil(totalPallets / palletsPerTruck);
                    const rate = parseInt(incomingLeg.delivery_rate) || 1;
                    const isDaily = incomingLeg.delivery_rate_unit === 'per_day';
                    const daysPerDelivery = isDaily ? 1 : 7;
                    const totalDays = Math.ceil(trucks / rate) * daysPerDelivery;

                    if (totalDays > 1) {
                        const startDate = new Date(stop.estimated_arrival_date);
                        const endDate = new Date(startDate);
                        endDate.setDate(endDate.getDate() + totalDays);
                        arrivalEnd = formatDateShort(endDate);
                    }
                }
            }

            // Build the arrival range display
            if (arrivalStart) {
                if (arrivalEnd && arrivalEnd !== arrivalStart) {
                    arrivalRangeHtml = `${arrivalStart} - ${arrivalEnd}`;
                } else {
                    arrivalRangeHtml = arrivalStart;
                }
            }

            // Calculate total pallets for the project
            const totalPallets = getTotalPallets();

            let statsHtml = '';
            if (stop.stop_type === 'origin' || stop.stop_type === 'destination') {
                statsHtml = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px;">
                        <div style="text-align: center; padding: 10px; background: ${config.bg}; border-radius: 8px;">
                            <div style="font-size: 18px; font-weight: 700; color: #293E4C;">${totalPallets.toLocaleString()}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">Pallets</div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: ${config.bg}; border-radius: 8px;">
                            <div style="font-size: 18px; font-weight: 700; color: #293E4C;">${totalStops}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">Stops</div>
                        </div>
                    </div>
                `;
            } else if (feeCount > 0 || totalFees > 0) {
                statsHtml = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px;">
                        <div style="text-align: center; padding: 10px; background: ${config.bg}; border-radius: 8px;">
                            <div style="font-size: 18px; font-weight: 700; color: #293E4C;">${feeCount}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">Fees</div>
                        </div>
                        <div style="text-align: center; padding: 10px; background: ${config.bg}; border-radius: 8px;">
                            <div style="font-size: 16px; font-weight: 700; color: #293E4C;">$${totalFees.toLocaleString()}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">Est. Cost</div>
                        </div>
                    </div>
                `;
            }

            return `
                <div style="font-family: 'Poppins', Arial, sans-serif; min-width: 220px; max-width: 280px;">
                    <div style="background: ${config.gradient}; padding: 14px 36px 14px 16px; display: flex; align-items: center; gap: 12px;">
                        <div style="width: 34px; height: 34px; background: rgba(255,255,255,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <span style="color: white; font-weight: 700; font-size: 15px;">${config.icon}</span>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 14px; font-weight: 600; color: white; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(stop.location_name || typeLabel)}</div>
                            <div style="font-size: 10px; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px;">Step ${stopIndex + 1} &bull; ${typeLabel}</div>
                        </div>
                    </div>
                    <div style="padding: 14px 16px;">
                        ${stop.location_address ? `
                        <div style="display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; background: #f8f9fa; border-radius: 8px; margin-bottom: 10px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span style="font-size: 12px; color: #555; line-height: 1.4;">${escapeHtml(stop.location_address)}</span>
                        </div>
                        ` : ''}
                        ${arrivalRangeHtml ? `
                        <div style="display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: #e3f4f7; border-radius: 6px; margin-bottom: 10px;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#488C9A" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span style="font-size: 12px; color: #488C9A; font-weight: 500;">Arrival: ${arrivalRangeHtml}</span>
                        </div>
                        ` : ''}
                        ${statsHtml}
                    </div>
                </div>
            `;
        }

        function updateMapFromState() {
            syncPlanState();
            if (!map) return;

            // Clear existing markers, polylines, and info windows
            mapMarkers.forEach(m => m.setMap(null));
            mapPolylines.forEach(p => p.setMap(null));
            mapMarkers = [];
            mapPolylines = [];

            const stops = workingState.stops || [];
            const placeholder = document.getElementById('mapPlaceholder');
            const statsOverlay = document.getElementById('mapStatsOverlay');

            if (stops.length === 0) {
                if (placeholder) placeholder.style.display = 'block';
                if (statsOverlay) statsOverlay.innerHTML = '';
                return;
            }

            const bounds = new google.maps.LatLngBounds();
            const pathCoordinates = [];
            const validStops = [];

            stops.forEach((stop, index) => {
                if (!stop.latitude || !stop.longitude) return;

                const position = { lat: parseFloat(stop.latitude), lng: parseFloat(stop.longitude) };
                pathCoordinates.push(position);
                bounds.extend(position);
                validStops.push({ stop, index, position });

                // Create custom SVG marker
                const marker = new google.maps.Marker({
                    position: position,
                    map: map,
                    title: stop.location_name || 'Stop ' + (index + 1),
                    icon: getMarkerSvg(stop.stop_type, index + 1),
                    zIndex: stop.stop_type === 'origin' ? 30 : (stop.stop_type === 'destination' ? 30 : 20),
                    animation: google.maps.Animation.DROP
                });

                // Rich info window
                const infoContent = buildInfoWindowContent(stop, index, stops.length);
                const infoWindow = new google.maps.InfoWindow({ content: infoContent });

                marker.addListener('click', () => {
                    // Close all other info windows
                    mapMarkers.forEach(m => {
                        if (m._infoWindow) m._infoWindow.close();
                    });
                    infoWindow.open(map, marker);
                });

                marker._infoWindow = infoWindow;
                mapMarkers.push(marker);
            });

            if (pathCoordinates.length === 0) {
                if (placeholder) placeholder.style.display = 'block';
                if (statsOverlay) statsOverlay.innerHTML = '';
                return;
            }

            if (placeholder) placeholder.style.display = 'none';

            // Draw segmented route polylines with volume-weighted thickness
            if (pathCoordinates.length > 1) {
                const totalPallets = getTotalPallets();
                const legs = workingState.legs || [];

                for (let i = 0; i < pathCoordinates.length - 1; i++) {
                    const fromStop = validStops[i];
                    const toStop = validStops[i + 1];

                    // Find the leg for this segment
                    let leg = null;
                    if (fromStop && toStop) {
                        leg = legs.find(l => l.from_stop_id == fromStop.stop.id && l.to_stop_id == toStop.stop.id);
                    }

                    // Determine line color based on segment type
                    let strokeColor = '#488C9A';
                    if (fromStop && fromStop.stop.stop_type === 'origin') {
                        strokeColor = '#3498db'; // Blue: from manufacturer
                    } else if (toStop && toStop.stop.stop_type === 'destination') {
                        strokeColor = '#27ae60'; // Green: to project
                    }

                    // Determine thickness based on truck count or default
                    let strokeWeight = 4;
                    if (leg) {
                        const trucks = parseInt(leg.trucks_required) || 0;
                        const maxTrucks = getTotalTrucks();
                        if (maxTrucks > 0) {
                            const ratio = trucks / maxTrucks;
                            strokeWeight = Math.max(3, Math.min(10, Math.round(ratio * 10)));
                        }
                    }

                    // Main route line
                    const routeSegment = new google.maps.Polyline({
                        path: [pathCoordinates[i], pathCoordinates[i + 1]],
                        geodesic: true,
                        strokeColor: strokeColor,
                        strokeOpacity: 0.85,
                        strokeWeight: strokeWeight,
                        map: map,
                        zIndex: 5
                    });

                    // Animated dashed overlay for visual interest
                    const dashOverlay = new google.maps.Polyline({
                        path: [pathCoordinates[i], pathCoordinates[i + 1]],
                        geodesic: true,
                        strokeColor: '#ffffff',
                        strokeOpacity: 0.25,
                        strokeWeight: strokeWeight > 4 ? 2 : 1,
                        map: map,
                        zIndex: 6,
                        icons: [{
                            icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.6, scale: 3 },
                            offset: '0',
                            repeat: '20px'
                        }]
                    });

                    // Click handler for route segments
                    const segmentInfoWindow = new google.maps.InfoWindow();
                    routeSegment.addListener('click', (event) => {
                        const fromName = fromStop ? escapeHtml(fromStop.stop.location_name || 'Origin') : 'Unknown';
                        const toName = toStop ? escapeHtml(toStop.stop.location_name || 'Destination') : 'Unknown';
                        const transportMode = leg ? (leg.transport_mode || 'truck') : 'truck';
                        const trucks = leg ? (parseInt(leg.trucks_required) || '—') : '—';
                        const freightCost = leg ? (parseFloat(leg.total_freight_cost) || 0) : 0;
                        const cadence = leg ? (parseInt(leg.delivery_rate) || 0) : 0;
                        const cadenceUnit = leg ? (leg.delivery_rate_unit === 'per_day' ? '/day' : '/week') : '/week';

                        const routeGradient = strokeColor === '#3498db' ? 'linear-gradient(135deg, #3498db, #2980b9)' :
                                              strokeColor === '#27ae60' ? 'linear-gradient(135deg, #27ae60, #1e8449)' :
                                              'linear-gradient(135deg, #488C9A, #3A6E7F)';

                        const transportIcons = {
                            truck: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                            ocean: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.6 2 5 2 2.4 0 2.4-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M19.38 20A11.6 11.6 0 0 0 21 14l-9-4-9 4c0 2.9.94 5.34 2.81 7.76"/></svg>',
                            rail: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/></svg>',
                            air: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>'
                        };

                        segmentInfoWindow.setContent(`
                            <div style="font-family: 'Poppins', Arial, sans-serif; min-width: 200px;">
                                <div style="background: ${routeGradient}; padding: 12px 36px 12px 14px; display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 30px; height: 30px; background: rgba(255,255,255,0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        ${transportIcons[transportMode] || transportIcons.truck}
                                    </div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 600; color: white;">Shipment Route</div>
                                        <div style="font-size: 10px; color: rgba(255,255,255,0.8); text-transform: capitalize;">${transportMode} transport</div>
                                    </div>
                                </div>
                                <div style="padding: 12px 14px;">
                                    <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px;">
                                        <div style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                                            <span style="color: #888; width: 38px;">From:</span>
                                            <span style="color: #333; font-weight: 500;">${fromName}</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                                            <span style="color: #888; width: 38px;">To:</span>
                                            <span style="color: #333; font-weight: 500;">${toName}</span>
                                        </div>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                                        <div style="text-align: center;">
                                            <div style="font-size: 15px; font-weight: 700; color: #293E4C;">${trucks}</div>
                                            <div style="font-size: 9px; color: #666; text-transform: uppercase;">Trucks</div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 15px; font-weight: 700; color: #293E4C;">${cadence > 0 ? cadence + cadenceUnit : '—'}</div>
                                            <div style="font-size: 9px; color: #666; text-transform: uppercase;">Cadence</div>
                                        </div>
                                        <div style="text-align: center;">
                                            <div style="font-size: 15px; font-weight: 700; color: #293E4C;">${freightCost > 0 ? '$' + freightCost.toLocaleString() : '—'}</div>
                                            <div style="font-size: 9px; color: #666; text-transform: uppercase;">Freight</div>
                                        </div>
                                    </div>
                                    <div style="margin-top: 8px; font-size: 10px; color: #aaa; text-align: center; font-style: italic;">Line thickness = relative volume</div>
                                </div>
                            </div>
                        `);
                        segmentInfoWindow.setPosition(event.latLng);
                        segmentInfoWindow.open(map);
                    });

                    mapPolylines.push(routeSegment);
                    mapPolylines.push(dashOverlay);
                }
            }

            // Fit bounds
            if (pathCoordinates.length > 0) {
                map.fitBounds(bounds);
                google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                    if (map.getZoom() > 15) map.setZoom(15);
                    if (pathCoordinates.length === 1 && map.getZoom() > 10) map.setZoom(10);
                });
            }

            // Update map stats overlay
            updateMapStatsOverlay(validStops);
        }

        function updateMapStatsOverlay(validStops) {
            const overlay = document.getElementById('mapStatsOverlay');
            if (!overlay) return;

            const stops = workingState.stops || [];
            const warehouseCount = stops.filter(s => !['origin', 'destination'].includes(s.stop_type)).length;
            const totalPallets = getTotalPallets();
            const legs = workingState.legs || [];
            const totalFreight = legs.reduce((sum, l) => sum + (parseFloat(l.total_freight_cost) || 0), 0);

            let chips = '';
            if (totalPallets > 0) {
                chips += `<div class="map-stat-chip"><span class="chip-dot" style="background: #488C9A;"></span>${totalPallets.toLocaleString()} pallets</div>`;
            }
            if (warehouseCount > 0) {
                chips += `<div class="map-stat-chip"><span class="chip-dot" style="background: #E07F3A;"></span>${warehouseCount} stop${warehouseCount > 1 ? 's' : ''}</div>`;
            }
            if (totalFreight > 0) {
                chips += `<div class="map-stat-chip"><span class="chip-dot" style="background: #28a745;"></span>$${totalFreight.toLocaleString()} freight</div>`;
            }

            overlay.innerHTML = chips;
        }

        function toggleMapFullscreen() {
            const mapView = document.getElementById('logistics-map-view');
            const mapContainer = document.querySelector('.route-map-container');
            if (!mapView || !mapContainer) return;

            const isFullscreen = mapView.classList.contains('map-fullscreen');

            if (isFullscreen) {
                mapView.classList.remove('map-fullscreen');
                mapContainer.style.height = '500px';
            } else {
                mapView.classList.add('map-fullscreen');
            }

            setTimeout(() => {
                if (map) {
                    google.maps.event.trigger(map, 'resize');
                    updateMapFromState();
                }
            }, 150);
        }

        // ==================== TIMELINE CHART ====================
        function updateTimelineChart() {
            syncPlanState();
            const container = document.getElementById('timelineChart');

            const events = [];
            let totalFreight = 0;
            let totalWarehousing = 0;
            const milestoneEvents = collectMilestoneEvents();
            const milestoneTotals = collectMilestoneTotals(milestoneEvents);
            const totalMilestones = milestoneEvents.reduce((sum, event) => sum + (event.amount || 0), 0);
            const addedMilestones = new Set();

            const poEventsByDate = {};
            milestoneEvents
                .filter(event => event.trigger === 'po_execution')
                .forEach(event => {
                    const date = event.date || new Date().toISOString().split('T')[0];
                    if (!poEventsByDate[date]) {
                        poEventsByDate[date] = { amount: 0, label: event.label || getMilestoneLabel('po_execution') };
                    }
                    poEventsByDate[date].amount += event.amount || 0;
                });

            // Collect events from stops (warehousing fees)
            workingState.stops.forEach((stop, stopIndex) => {
                if (stop.stop_type === 'origin' || stop.stop_type === 'destination') return;

                const stopFees = (stop.fees || []).reduce((sum, fee) => sum + (parseFloat(fee.estimated_cost) || 0), 0);

                // Calculate fees including monthly fees across time
                const arrivalDate = stop.estimated_arrival_date || null;
                const nextStop = workingState.stops[stopIndex + 1];
                const departureDate = nextStop?.estimated_arrival_date || null;

                if (stopFees > 0) {
                    totalWarehousing += stopFees;

                    // Add warehousing event
                    events.push({
                        date: arrivalDate || new Date().toISOString().split('T')[0],
                        label: stop.location_name || 'Warehouse',
                        type: 'warehousing',
                        amount: stopFees
                    });
                }
            });

            // Collect events from legs (freight costs)
            workingState.legs.forEach(leg => {
                const legCost = parseFloat(leg.total_freight_cost) || 0;
                const legDate = leg.start_date || leg.end_date || null;

                if (legCost > 0) {
                    totalFreight += legCost;

                    events.push({
                        date: legDate || new Date().toISOString().split('T')[0],
                        label: getTransportModeLabel(leg.transport_mode),
                        type: 'freight',
                        amount: legCost
                    });
                }

                // Add milestone as a separate event if triggered
                if (leg.triggers_milestone && milestoneTotals[leg.triggers_milestone] && !addedMilestones.has(leg.triggers_milestone)) {
                    const milestoneInfo = milestoneTotals[leg.triggers_milestone];
                    const milestoneAmount = milestoneInfo.amount || 0;
                    if (milestoneAmount > 0) {
                        addedMilestones.add(leg.triggers_milestone);

                        events.push({
                            date: leg.end_date || leg.start_date || new Date().toISOString().split('T')[0],
                            label: milestoneInfo.label || getMilestoneLabel(leg.triggers_milestone),
                            type: 'milestone',
                            amount: milestoneAmount
                        });
                    }
                }
            });

            Object.entries(poEventsByDate).forEach(([date, info]) => {
                if (info.amount <= 0) return;
                events.push({
                    date,
                    label: info.label,
                    type: 'milestone',
                    amount: info.amount
                });
            });

            // Sort by date
            events.sort((a, b) => new Date(a.date) - new Date(b.date));

            if (container) {
                // Handle empty state
                if (events.length === 0) {
                    container.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #6c757d; width: 100%;">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" style="margin-bottom: 12px;">
                                <line x1="18" y1="20" x2="18" y2="10"/>
                                <line x1="12" y1="20" x2="12" y2="4"/>
                                <line x1="6" y1="20" x2="6" y2="14"/>
                            </svg>
                            <p style="margin: 0;">Add costs to your stops and legs to see the timeline chart.</p>
                        </div>
                    `;
                } else {
                    // Generate bars
                    const maxAmount = Math.max(...events.map(e => e.amount), 1);

                    container.innerHTML = events.map((event, i) => {
                        const height = Math.max((event.amount / maxAmount) * 140, 20);
                        const formattedDate = formatDate(event.date);
                        const formattedAmount = '$' + (event.amount || 0).toLocaleString();

                        return `
                            <div class="timeline-bar">
                                <div class="timeline-bar-fill ${event.type}" style="height: ${height}px;">
                                    <span class="timeline-bar-value">${formattedAmount}</span>
                                </div>
                                <div class="timeline-bar-label">
                                    <span class="timeline-bar-date">${formattedDate}</span>
                                    ${event.label}
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }

            // Update cumulative displays
            const summaryFreight = parseFloat(costSummary.total_freight_cost) || 0;
            const summaryWarehousing = parseFloat(costSummary.total_warehousing_cost) || 0;
            const summaryMilestones = parseFloat(costSummary.total_milestone_payments) || 0;

            const displayFreight = totalFreight || summaryFreight;
            const displayWarehousing = totalWarehousing || summaryWarehousing;
            const displayMilestones = totalMilestones || summaryMilestones;
            const grandTotal = displayFreight + displayWarehousing + displayMilestones;

            const totalFreightDisplay = document.getElementById('totalFreightDisplay');
            if (totalFreightDisplay) {
                totalFreightDisplay.textContent = '$' + displayFreight.toLocaleString();
            }

            const totalWarehousingDisplay = document.getElementById('totalWarehousingDisplay');
            if (totalWarehousingDisplay) {
                totalWarehousingDisplay.textContent = '$' + displayWarehousing.toLocaleString();
            }

            const totalMilestonesDisplay = document.getElementById('totalMilestonesDisplay');
            if (totalMilestonesDisplay) {
                totalMilestonesDisplay.textContent = '$' + displayMilestones.toLocaleString();
            }

            const grandTotalDisplay = document.getElementById('grandTotalDisplay');
            if (grandTotalDisplay) {
                grandTotalDisplay.textContent = '$' + grandTotal.toLocaleString();
            }

            // Also update the badge in the collapsible header
            const grandTotalBadge = document.getElementById('grandTotalBadge');
            if (grandTotalBadge) {
                grandTotalBadge.textContent = '$' + grandTotal.toLocaleString();
            }

            const timelineFreightSummary = document.getElementById('timelineFreightSummary');
            if (timelineFreightSummary) {
                timelineFreightSummary.textContent = `$${displayFreight.toLocaleString()} Freight`;
                timelineFreightSummary.classList.toggle('hidden', displayFreight <= 0);
            }

            const timelineWarehousingSummary = document.getElementById('timelineWarehousingSummary');
            if (timelineWarehousingSummary) {
                timelineWarehousingSummary.textContent = `$${displayWarehousing.toLocaleString()} Warehousing`;
                timelineWarehousingSummary.classList.toggle('hidden', displayWarehousing <= 0);
            }

            const timelineMilestoneSummary = document.getElementById('timelineMilestoneSummary');
            if (timelineMilestoneSummary) {
                timelineMilestoneSummary.textContent = `$${displayMilestones.toLocaleString()} Milestones`;
                timelineMilestoneSummary.classList.toggle('hidden', displayMilestones <= 0);
            }

            const timelineDateRange = document.getElementById('timelineDateRange');
            if (timelineDateRange) {
                const dates = [];
                (workingState.legs || []).forEach(leg => {
                    if (leg.start_date) dates.push(new Date(leg.start_date));
                    if (leg.end_date) dates.push(new Date(leg.end_date));
                });
                (workingState.stops || []).forEach(stop => {
                    if (stop.estimated_arrival_date) dates.push(new Date(stop.estimated_arrival_date));
                    if (stop.estimated_departure_date) dates.push(new Date(stop.estimated_departure_date));
                });

                const validDates = dates.filter(date => !isNaN(date.getTime()));
                if (validDates.length > 0) {
                    const minDate = new Date(Math.min(...validDates.map(date => date.getTime())));
                    const maxDate = new Date(Math.max(...validDates.map(date => date.getTime())));
                    const startLabel = minDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    const endLabel = maxDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    timelineDateRange.textContent = `${startLabel} → ${endLabel}`;
                } else {
                    timelineDateRange.textContent = 'No dates yet';
                }
            }

            updateStepperState();
        }

        function getTransportModeLabel(mode) {
            const labels = {
                'truck': 'Truck',
                'ocean': 'Ocean',
                'rail': 'Rail',
                'air': 'Air'
            };
            return labels[mode] || mode;
        }

        function getMilestoneLabel(milestone) {
            const labels = {
                'po_execution': 'PO Execution',
                'shipping': 'Shipping',
                'customs_cleared': 'Customs',
                'project_delivery': 'Delivery'
            };
            return labels[milestone] || milestone;
        }

        function collectMilestoneEvents() {
            const events = [];

            workingState.moduleAllocations.forEach(alloc => {
                const contractValue = parseFloat(alloc.contract_value) || 0;
                if (!contractValue || !Array.isArray(alloc.milestones)) {
                    return;
                }

                alloc.milestones.forEach(milestone => {
                    const trigger = milestone.trigger_event;
                    const percentage = parseFloat(milestone.percentage) || 0;
                    if (!trigger || percentage <= 0) {
                        return;
                    }

                    const poDate = alloc.po_execution_date || alloc.poExecutionDate || '';
                    const eventDate = trigger === 'po_execution' ? poDate : '';

                    events.push({
                        trigger,
                        amount: contractValue * (percentage / 100),
                        label: milestone.milestone_name || getMilestoneLabel(trigger),
                        date: eventDate
                    });
                });
            });

            return events;
        }

        function collectMilestoneTotals(milestoneEvents = null) {
            const events = milestoneEvents || collectMilestoneEvents();
            const totals = {};

            events.forEach(event => {
                if (!totals[event.trigger]) {
                    totals[event.trigger] = {
                        amount: 0,
                        label: event.label || getMilestoneLabel(event.trigger)
                    };
                }
                totals[event.trigger].amount += event.amount;
            });

            return totals;
        }

        function getMilestoneAmount(milestone, milestoneTotals = null) {
            const totals = milestoneTotals || collectMilestoneTotals();
            return totals[milestone]?.amount || 0;
        }

        // Note: formatDate is defined earlier in the file

        // ==================== COMPARISON TABLE ====================
        function updateComparisonTable() {
            syncPlanState();
            const tbody = document.getElementById('comparisonTableBody');
            if (!tbody) return;

            // Get projected and actual values
            const projected = {
                milestones: 0,
                freight: 0,
                warehousing: 0
            };

            const actual = {
                milestones: 0,
                freight: 0,
                warehousing: 0
            };

            // Calculate projected
            workingState.moduleAllocations.forEach(alloc => {
                projected.milestones += alloc.contract_value || 0;
            });

            workingState.legs.forEach(leg => {
                projected.freight += leg.total_freight_cost || 0;
            });

            workingState.stops.forEach(stop => {
                (stop.fees || []).forEach(fee => {
                    projected.warehousing += fee.estimated_cost || 0;
                });
            });

            // Calculate actual (would come from actual_deliveries data)
            if (currentProjection && currentProjection.actual_deliveries) {
                currentProjection.actual_deliveries.forEach(delivery => {
                    actual.milestones += delivery.milestone_amount || 0;
                    actual.freight += delivery.freight_cost || 0;
                    actual.warehousing += delivery.warehousing_cost || 0;
                });
            }

            const categories = [
                { label: 'Milestone Payments', projected: projected.milestones, actual: actual.milestones },
                { label: 'Freight Costs', projected: projected.freight, actual: actual.freight },
                { label: 'Warehousing', projected: projected.warehousing, actual: actual.warehousing }
            ];

            const totalProjected = projected.milestones + projected.freight + projected.warehousing;
            const totalActual = actual.milestones + actual.freight + actual.warehousing;

            tbody.innerHTML = categories.map(cat => {
                const variance = cat.projected > 0 ? ((cat.actual / cat.projected) * 100).toFixed(0) : 0;
                const varianceClass = cat.actual <= cat.projected ? 'variance-positive' : 'variance-negative';
                return `
                    <tr>
                        <td>${cat.label}</td>
                        <td>$${cat.projected.toLocaleString()}</td>
                        <td>$${cat.actual.toLocaleString()}</td>
                        <td class="${varianceClass}">${variance}%</td>
                    </tr>
                `;
            }).join('') + `
                <tr>
                    <td><strong>TOTAL</strong></td>
                    <td><strong>$${totalProjected.toLocaleString()}</strong></td>
                    <td><strong>$${totalActual.toLocaleString()}</strong></td>
                    <td class="${totalActual <= totalProjected ? 'variance-positive' : 'variance-negative'}">
                        <strong>${totalProjected > 0 ? ((totalActual / totalProjected) * 100).toFixed(0) : 0}%</strong>
                    </td>
                </tr>
            `;

            // Update progress bar
            if (currentProjection && currentProjection.actual_deliveries) {
                const completedDeliveries = currentProjection.actual_deliveries.length;
                const totalDeliveries = getTotalTrucks();
                const progress = totalDeliveries > 0 ? (completedDeliveries / totalDeliveries) * 100 : 0;

                document.getElementById('deliveryProgressBar').style.width = progress + '%';
                document.getElementById('deliveryProgressText').textContent =
                    `${completedDeliveries} of ${totalDeliveries} deliveries completed`;
            }
        }

        // ==================== UNSAVED CHANGES TRACKING ====================
        let hasUnsavedChanges = false;

        function markAsUnsaved() {
            hasUnsavedChanges = true;
            // Show unsaved indicator
            const indicator = document.getElementById('autosaveIndicator');
            if (indicator) {
                indicator.classList.add('visible');
                indicator.classList.remove('saving', 'saved');
                indicator.querySelector('span').textContent = 'Unsaved changes';
            }
        }

        function markAsSaved() {
            hasUnsavedChanges = false;
            const indicator = document.getElementById('autosaveIndicator');
            if (indicator) {
                indicator.classList.remove('visible', 'saving');
            }
        }

        // ==================== KEYBOARD SHORTCUTS ====================
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (canEdit) saveProjection();
            }

            // Ctrl+N for new projection
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                if (canEdit) createNewProjection();
            }

            // Ctrl+W for new warehouse stop
            if ((e.ctrlKey || e.metaKey) && e.key === 'w') {
                e.preventDefault();
                if (canEdit) addWarehouseDelivery();
            }
        });

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initializeDatePickers();
            updateUIFromState();

            // Initialize map after page load
            if (typeof google !== 'undefined' && google.maps) {
                initializeMap();
            }

            // Initialize timeline
            updateTimelineChart();

            // Initialize comparison table
            updateComparisonTable();

            // Warn before leaving with unsaved changes
            window.addEventListener('beforeunload', function(e) {
                if (hasUnsavedChanges) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });

        // Re-initialize map when Google Maps loads
        if (typeof google === 'undefined') {
            window.initMap = initializeMap;
        }
    </script>
