<?php
/**
 * Project Overview Views Component - Unified Version
 * Contains the main tabs and content sections for project overview
 *
 * Structure:
 * - Main Tabs: Project Overview | Analytics
 * - Project Overview Sub-tabs: Timeline | Site | Modules
 * - Analytics Sub-tabs: Deliveries | Financial
 * - Unit filters only on Timeline and Deliveries sub-tabs
 *
 * Required variables from data_processing.php:
 * - $role, $isAdmin, $isGlobalAdmin: User role information
 * - $project_id, $project: Project data
 * - $deliveriesLink: Link to deliveries page based on role
 * - All step completion variables, status totals, module data, etc.
 */
?>

<style>
/* Document Grid Styles */
.docs-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.doc-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    transition: all 0.2s ease;
    min-width: 200px;
    max-width: 300px;
}
.doc-item:hover {
    background: #e9ecef;
    border-color: #488C9A;
    transform: translateY(-1px);
}
.doc-item i {
    font-size: 1.25rem;
    width: 24px;
    text-align: center;
}
.doc-item .doc-details {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}
.doc-item .doc-name {
    font-weight: 500;
    color: #293E4C;
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.doc-item .doc-subtype {
    font-size: 0.75rem;
    color: #6c757d;
}
.docs-count-link {
    font-size: 0.85rem;
    color: #488C9A;
    text-decoration: none;
    font-weight: 400;
    margin-left: 8px;
}
.docs-count-link:hover {
    text-decoration: underline;
}
.docs-loading {
    color: #6c757d;
    padding: 20px;
    text-align: center;
    width: 100%;
}
.docs-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 30px;
    color: #6c757d;
    font-style: italic;
    background: #f8f9fa;
    border-radius: 8px;
    width: 100%;
}
.docs-empty i {
    font-size: 2rem;
    opacity: 0.5;
}
.docs-error {
    color: #dc3545;
    padding: 20px;
    text-align: center;
    width: 100%;
}

/* Compact docs grid for batch sections */
.docs-grid.compact {
    gap: 8px;
}
.doc-item.compact {
    padding: 8px 12px;
    min-width: 180px;
    max-width: 260px;
}
.doc-item.compact i {
    font-size: 1.1rem;
}
.doc-item.compact .doc-name {
    font-size: 0.85rem;
}
.doc-item.compact .doc-subtype {
    font-size: 0.7rem;
}

/* Batch documents section styling */
.batch-docs-section {
    padding-top: 16px;
    border-top: 1px solid #e9ecef;
}

/* Document Preview Modal */
.doc-preview-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}
.doc-preview-modal.open {
    display: flex;
}
.doc-preview-content {
    background: #fff;
    border-radius: 16px;
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}
.doc-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
}
.doc-preview-header h3 {
    margin: 0;
    color: #293E4C;
    font-size: 1rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 70%;
}
.doc-preview-header .preview-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}
.doc-preview-header .preview-actions a,
.doc-preview-header .preview-actions button {
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
}
.doc-preview-header .btn-download {
    background: #488C9A;
    color: #fff;
    border: none;
}
.doc-preview-header .btn-download:hover {
    background: #3a7a87;
}
.doc-preview-header .btn-close-preview {
    background: #f8f9fa;
    color: #495057;
    border: 1px solid #dee2e6;
}
.doc-preview-header .btn-close-preview:hover {
    background: #e9ecef;
}
.doc-preview-body {
    flex: 1;
    overflow: auto;
    padding: 0;
    background: #e9ecef;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 400px;
}
.doc-preview-body iframe {
    width: 100%;
    height: 100%;
    border: none;
    min-height: 500px;
}
.doc-preview-body img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.doc-preview-body .no-preview {
    text-align: center;
    padding: 60px;
    color: #6c757d;
}
.doc-preview-body .no-preview i {
    font-size: 4rem;
    display: block;
    margin-bottom: 16px;
    opacity: 0.5;
}
</style>

<!-- Document Preview Modal -->
<div id="docPreviewModal" class="doc-preview-modal" onclick="if(event.target === this) closeDocPreview()">
    <div class="doc-preview-content">
        <div class="doc-preview-header">
            <h3 id="docPreviewTitle">Document</h3>
            <div class="preview-actions">
                <a id="docPreviewDownload" href="#" class="btn-download" download><i class="fas fa-download"></i> Download</a>
                <button class="btn-close-preview" onclick="closeDocPreview()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
        <div class="doc-preview-body" id="docPreviewBody">
            <!-- Preview content loaded here -->
        </div>
    </div>
</div>

<script>
function openDocPreview(filePath, fileName, ext) {
    const modal = document.getElementById('docPreviewModal');
    const title = document.getElementById('docPreviewTitle');
    const downloadBtn = document.getElementById('docPreviewDownload');
    const body = document.getElementById('docPreviewBody');

    title.textContent = fileName;
    downloadBtn.href = filePath;

    const previewableImages = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const previewableDocs = ['pdf'];

    if (previewableImages.includes(ext.toLowerCase())) {
        body.innerHTML = `<img src="${filePath}" alt="${fileName}" style="max-width: 100%; max-height: 70vh;">`;
    } else if (previewableDocs.includes(ext.toLowerCase())) {
        body.innerHTML = `<iframe src="${filePath}" style="width: 100%; height: 70vh;"></iframe>`;
    } else {
        let icon = 'fa-file';
        if (['doc', 'docx'].includes(ext)) icon = 'fa-file-word';
        else if (['xls', 'xlsx'].includes(ext)) icon = 'fa-file-excel';
        else if (['ppt', 'pptx'].includes(ext)) icon = 'fa-file-powerpoint';

        body.innerHTML = `
            <div class="no-preview">
                <i class="fas ${icon}"></i>
                <p>Preview not available for this file type</p>
                <p style="font-size: 0.9rem; margin-top: 8px;">Click "Download" to view the file</p>
            </div>
        `;
    }

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDocPreview() {
    const modal = document.getElementById('docPreviewModal');
    modal.classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('docPreviewBody').innerHTML = '';
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('docPreviewModal').classList.contains('open')) {
        closeDocPreview();
    }
});
</script>

<div class="project-tabs-row">
    <span class="project-tabs-label">Overview:</span>
    <div class="project-tabs">
        <button class="tab-btn active" data-tab="tab-timeline">Timeline</button>
        <button class="tab-btn" data-tab="tab-site">Site</button>
        <button class="tab-btn" data-tab="tab-modules">Modules</button>
        <button class="tab-btn" data-tab="tab-deliveries">Deliveries</button>
    </div>
</div>

<!-- Timeline Tab -->
<div id="tab-timeline" class="tab-content active">
        <?php
        $next_actions = [];
        $next_action_keys = [];
        $push_next_action = function ($key, $label, $url, $icon, $description) use (&$next_actions, &$next_action_keys) {
            if (isset($next_action_keys[$key])) {
                return;
            }
            if (count($next_actions) >= 5) {
                return;
            }
            $next_action_keys[$key] = true;
            $next_actions[] = ['label' => $label, 'url' => $url, 'icon' => $icon, 'description' => $description, 'recommended' => false];
        };

        $at_manufacturer_pallets = (int)($status_totals['At Manufacturer']['pallets'] ?? 0);
        $in_warehouse_pallets = (int)($status_totals['In Warehouse']['pallets'] ?? 0);
        $in_transit_to_project_pallets = (int)($status_totals['In Transit to Project']['pallets'] ?? 0);

        if ($can_add_modules && !$step2_completed) {
            $push_next_action('add_modules', 'Add Modules', 'add_module_batch.php?project_id=' . (int)$project_id, 'fa-cubes', 'Add module batches to this project');
        }
        if ($step2_completed && !$step3_completed) {
            $push_next_action(
                'palletize_modules',
                $isAdmin ? 'Palletize Modules' : 'View Pallets',
                ($isAdmin ? 'module_overview.php' : 'manage_pallets.php') . '?project_id=' . (int)$project_id,
                'fa-boxes-stacked',
                'Configure pallet packaging for modules'
            );
        }
        if (!$has_legs_with_dates) {
            $push_next_action('add_plan', 'Add Plan', 'anticipated_deliveries.php?project_id=' . (int)$project_id, 'fa-calendar-alt', 'Set up delivery schedule and planning');
        }
        if ($isAdmin && $step3_completed && ($at_manufacturer_pallets > 0 || $in_warehouse_pallets > 0)) {
            $push_next_action('create_shipment', 'Create Shipment', 'create_shipment.php?project_id=' . (int)$project_id, 'fa-truck', 'Ship pallets from manufacturer or warehouse');
        }
        if ($isAdmin && $step3_completed && $in_transit_to_project_pallets > 0) {
            $push_next_action('schedule_delivery', 'Schedule Delivery', 'scheduling.php?project_id=' . (int)$project_id, 'fa-calendar-check', 'Schedule deliveries for in-transit pallets');
        }
        if ($isAdmin) {
            $push_next_action('manage_deliveries', 'Manage Deliveries', 'manage_deliveries.php?project_id=' . (int)$project_id, 'fa-clipboard-list', 'Track and manage active deliveries');
        } else {
            $push_next_action('view_deliveries', 'View Deliveries', $deliveriesLink, 'fa-eye', 'View delivery status and history');
        }
        $push_next_action('supply_chain_map', 'Supply Chain Map', 'module_movements.php?project_id=' . (int)$project_id, 'fa-map-marked-alt', 'View module movement across supply chain');

        if (!empty($next_actions)) {
            $next_actions[0]['recommended'] = true;
        }
        ?>

        <?php if (!empty($next_actions)): ?>
        <div class="next-actions-card" id="nextActionsCard">
            <div class="next-actions-card-header" onclick="toggleNextActions()" style="cursor:pointer;">
                <div class="next-actions-card-icon"><i class="fas fa-compass"></i></div>
                <span class="next-actions-card-title">Suggested Next Actions</span>
                <i class="fas fa-chevron-down next-actions-chevron" id="nextActionsChevron"></i>
            </div>
            <div class="next-action-items" id="nextActionsBody" style="display:none;">
                <?php foreach ($next_actions as $action): ?>
                <a href="<?php echo htmlspecialchars($action['url']); ?>" class="next-action-item<?php echo !empty($action['recommended']) ? ' recommended' : ''; ?>">
                    <div class="next-action-item-icon"><i class="fas <?php echo htmlspecialchars($action['icon']); ?>"></i></div>
                    <div class="next-action-item-text">
                        <span class="next-action-item-label"><?php echo htmlspecialchars($action['label']); ?></span>
                        <span class="next-action-item-desc"><?php echo htmlspecialchars($action['description']); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function() {
            var projectId = <?php echo (int)$project_id; ?>;
            var body = document.getElementById('nextActionsBody');
            var chevron = document.getElementById('nextActionsChevron');
            if (body && localStorage.getItem('nextActionsOpen_' + projectId)) {
                body.style.display = '';
                if (chevron) chevron.classList.add('open');
            }
        })();
        function toggleNextActions() {
            var projectId = <?php echo (int)$project_id; ?>;
            var body = document.getElementById('nextActionsBody');
            var chevron = document.getElementById('nextActionsChevron');
            if (!body) return;
            var isOpen = body.style.display !== 'none';
            body.style.display = isOpen ? 'none' : '';
            if (chevron) chevron.classList.toggle('open', !isOpen);
            if (isOpen) {
                localStorage.removeItem('nextActionsOpen_' + projectId);
            } else {
                localStorage.setItem('nextActionsOpen_' + projectId, '1');
            }
        }
        </script>
        <?php endif; ?>

        <div class="unit-filter-bar unit-filter-bar-overview">
            <span class="filter-label">View as:</span>
            <div class="filter-chips">
                <button type="button" class="filter-chip active" data-unit="mws">MWs</button>
                <button type="button" class="filter-chip" data-unit="modules">Modules</button>
                <button type="button" class="filter-chip" data-unit="pallets">Pallets</button>
                <button type="button" class="filter-chip" data-unit="truckloads">Trucks</button>
            </div>
        </div>

        <div class="timeline-container">
            <ul class="timeline<?php echo ($current_step >= 4) ? ' progress-past-planning' : ''; ?>" style="--progress-width: <?php echo $progress_percentage; ?>%">

                <!-- Step 1: Project Created -->
                <li class="timeline-item<?php echo $step1_completed ? ' completed' : ''; ?><?php echo $current_step == 1 ? ' current' : ''; ?>">
                    <?php
                    $step1_url = $isAdmin ? "edit_project.php?project_id={$project_id}" : "project_information.php?project_id={$project_id}";
                    ?>
                    <div class="circle clickable" onclick="window.location.href='<?php echo $step1_url; ?>'">1</div>
                    <span class="label">
                        <a href="<?php echo $step1_url; ?>">Project Created</a>
                    </span>
                    <div class="description">Foundation established</div>
                </li>

                <!-- Step 2: Add Modules -->
                <li class="timeline-item<?php echo $step2_completed ? ' completed' : ''; ?><?php echo $current_step == 2 ? ' current' : ''; ?>">
                    <?php if ($isGlobalAdmin): ?>
                        <div class="circle clickable" onclick="window.location.href='add_module_batch.php?project_id=<?php echo $project_id; ?>'">2</div>
                        <span class="label">
                            <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>">Add Modules</a>
                        </span>
                    <?php elseif ($step2_completed): ?>
                        <div class="circle clickable" onclick="window.location.href='module_overview.php?project_id=<?php echo $project_id; ?>'">2</div>
                        <span class="label">
                            <a href="module_overview.php?project_id=<?php echo $project_id; ?>">Modules Added</a>
                        </span>
                    <?php else: ?>
                        <div class="circle">2</div>
                        <span class="label">Add Modules</span>
                    <?php endif; ?>
                    <div class="description">
                        <?php echo number_format($total_raw_modules); ?> modules added
                        <?php if ($ordered_mw > 0 && $project_size_mw > 0):
                            $mw_diff = $ordered_mw - $project_size_mw;
                            $mw_percentage = round(($ordered_mw / $project_size_mw) * 100);
                        ?>
                        <div style="font-size: 10px; color: #488C9A; margin-top: 2px;">
                            (<?php echo number_format($ordered_mw, 2); ?> / <?php echo number_format($project_size_mw, 2); ?> MW)
                        </div>
                        <?php if ($mw_diff < 0): ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(255, 193, 7, 0.12); border-radius: 3px; color: #856404;">
                            <?php echo number_format(abs($mw_diff), 2); ?> MW short of target
                        </div>
                        <?php elseif ($mw_diff > 0): ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(40, 167, 69, 0.12); border-radius: 3px; color: #155724;">
                            +<?php echo number_format($mw_diff, 2); ?> MW over target
                        </div>
                        <?php else: ?>
                        <div style="font-size: 9px; margin-top: 3px; padding: 2px 6px; display: inline-block; background: rgba(40, 167, 69, 0.12); border-radius: 3px; color: #155724;">
                            Target reached
                        </div>
                        <?php endif; ?>
                        <?php elseif ($ordered_mw > 0): ?>
                        <div style="font-size: 10px; color: #488C9A; margin-top: 2px;">(<?php echo number_format($ordered_mw, 2); ?> MW)</div>
                        <?php endif; ?>
                    </div>
                </li>

                <!-- Step 3: Palletize Modules -->
                <li class="timeline-item<?php echo $step3_completed ? ' completed' : ''; ?><?php echo $current_step == 3 ? ' current' : ''; ?>">
                    <?php
                    $step3_clickable = $isAdmin || $step3_completed;
                    $step3_url = $isAdmin ? "module_overview.php?project_id={$project_id}" : "manage_pallets.php?project_id={$project_id}";
                    ?>
                    <div class="circle<?php echo $step3_clickable ? ' clickable' : ''; ?>"
                         <?php echo $step3_clickable ? "onclick=\"window.location.href='{$step3_url}'\"" : ''; ?>>3</div>
                    <span class="label">
                        <?php if ($step3_clickable): ?>
                            <a href="<?php echo $step3_url; ?>">
                                <?php echo $isAdmin ? 'Palletize Modules' : 'Modules Palletized'; ?>
                            </a>
                        <?php else: ?>
                            Modules Palletized
                        <?php endif; ?>
                    </span>
                    <div class="description">
                        <?php echo number_format($actual_palletized_count) . ' of ' . number_format($expected_pallets) . ' palletized'; ?>
                    </div>
                </li>

                <!-- Project Planning (Optional Step) -->
                <li class="timeline-item timeline-planning-step<?php echo $has_legs_with_dates ? ' completed' : ($has_modules_only ? ' partial' : ''); ?>">
                    <div class="planning-wrap"></div>
                    <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>" class="circle circle-planning clickable" title="<?php echo htmlspecialchars($planning_badge['title'] ?? ''); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </a>
                    <span class="label">
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>">Project Planning</a>
                    </span>
                    <div class="description">
                        <?php if ($has_legs_with_dates): ?>
                            <span style="color: #28a745;"><i class="fas fa-check-circle"></i> Plan Set</span>
                        <?php elseif ($has_modules_only): ?>
                            <span style="color: #ffc107;"><i class="fas fa-clock"></i> In Progress</span>
                        <?php else: ?>
                            <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>" style="color: #488C9A;"><i class="fas fa-plus-circle"></i> Add Plan</a>
                        <?php endif; ?>
                        <div style="font-size: 9px; color: #6c757d; margin-top: 2px;">Enables forecasting & analytics</div>
                    </div>
                </li>

                <!-- Step 4: Shipping -->
                <li class="timeline-item<?php echo $step4_completed ? ' completed' : ''; ?><?php echo $current_step == 4 ? ' current' : ''; ?>">
                    <div class="circle circle-map clickable" onclick="window.location.href='module_movements.php?project_id=<?php echo $project_id; ?>'">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                            <circle cx="12" cy="10" r="3"></circle>
                        </svg>
                    </div>
                    <span class="label">
                        <a href="module_movements.php?project_id=<?php echo $project_id; ?>">Shipping</a>
                    </span>
                    <div class="description"><a href="module_movements.php?project_id=<?php echo $project_id; ?>" class="description-link">View Supply Chain Map</a></div>

                    <?php if ($current_step >= 4): ?>
                    <div class="shipping-connector"></div>
                    <div class="shipping-boxes-container">
                        <?php
                        $shipping_statuses_list = [
                            ["key" => "At Manufacturer", "class" => "at-manufacturer", "label" => "At Manufacturer"],
                            ["key" => "On Water", "class" => "on-water", "label" => "On Water"],
                            ["key" => "Customs Hold", "class" => "customs-hold", "label" => "Customs Hold"],
                            ["key" => "Cleared Customs", "class" => "cleared-customs", "label" => "Cleared Customs"],
                            ["key" => "In Transit to Warehouse", "class" => "in-transit-warehouse", "label" => "In Transit to Warehouse"],
                            ["key" => "In Warehouse", "class" => "in-warehouse", "label" => "In Warehouse"],
                            ["key" => "In Transit to Project", "class" => "in-transit-project", "label" => "In Transit to Project"],
                            ["key" => "Delivered", "class" => "delivered", "label" => "Delivered"],
                        ];

                        // Statuses whose detailed_breakdown splits by location ("Status - Name")
                        $split_statuses = ['In Warehouse', 'In Transit to Warehouse', 'In Transit to Project'];
                        // Short prefixes used when we render one box per location
                        $split_prefixes = [
                            'In Warehouse' => 'At',
                            'In Transit to Warehouse' => 'To',
                            'In Transit to Project' => 'To',
                        ];
                        $avg_watt_for_mw = 0;
                        if (!empty($wattages)) {
                            $avg_watt_for_mw = count($wattages) > 0 ? (array_sum($wattages) / count($wattages)) : 0;
                        }
                        $mw_from_modules = function ($m) use ($avg_watt_for_mw) {
                            return ($avg_watt_for_mw > 0 && $m > 0) ? round(($m * $avg_watt_for_mw) / 1000000, 2) : 0;
                        };
                        $trucks_from = function ($p, $m) use ($average_pallets_per_truck, $average_modules_per_truck) {
                            if (!empty($average_pallets_per_truck)) return round($p / $average_pallets_per_truck, 1);
                            if (!empty($average_modules_per_truck)) return round($m / $average_modules_per_truck, 1);
                            return null;
                        };

                        foreach ($shipping_statuses_list as $status_info):
                            $status_key = $status_info["key"];
                            $pallets = $status_totals[$status_key]["pallets"] ?? 0;
                            $modules = $status_totals[$status_key]["modules"] ?? 0;
                            if ($pallets <= 0) continue;

                            // For multi-location statuses, emit one box per location from detailed_breakdown
                            $rendered_split = false;
                            if (in_array($status_key, $split_statuses, true)) {
                                $prefix = $status_key . ' - ';
                                $locations = [];
                                foreach ($detailed_breakdown as $bd_key => $bd) {
                                    if (strpos($bd_key, $prefix) !== 0) continue;
                                    $loc_pallets = (int)($bd['pallet_count'] ?? 0);
                                    if ($loc_pallets <= 0) continue;
                                    $locations[] = [
                                        'name' => substr($bd_key, strlen($prefix)),
                                        'key' => $bd_key,
                                        'pallets' => $loc_pallets,
                                        'modules' => (int)($bd['total_modules'] ?? 0),
                                    ];
                                }
                                if (!empty($locations)) {
                                    $rendered_split = true;
                                    $short = $split_prefixes[$status_key] ?? '';
                                    foreach ($locations as $loc):
                                        $loc_trucks = $trucks_from($loc['pallets'], $loc['modules']);
                                        $loc_mws = $mw_from_modules($loc['modules']);
                                        $click_key_js = json_encode($loc['key']);
                                        $click = $isAdmin
                                            ? "showShippingBreakdown('{$status_key}', {$click_key_js})"
                                            : "showCustomerShippingModal('{$status_key}', {$click_key_js})";
                                ?>
                        <div class="shipping-box <?php echo $status_info["class"]; ?> split-location"
                             onclick="<?php echo htmlspecialchars($click, ENT_QUOTES); ?>"
                             data-pallets="<?php echo (int)$loc['pallets']; ?>"
                             data-modules="<?php echo (int)$loc['modules']; ?>"
                             data-truckloads="<?php echo ($loc_trucks !== null ? $loc_trucks : ''); ?>"
                             data-mws="<?php echo $loc_mws; ?>"
                             title="<?php echo htmlspecialchars($status_info['label'] . ' · ' . $loc['name']); ?>">
                            <div class="status-label">
                                <?php if ($short !== ''): ?><span class="status-prefix"><?php echo $short; ?></span><?php endif; ?>
                                <span class="location-name"><?php echo htmlspecialchars($loc['name']); ?></span>
                            </div>
                            <div class="status-count"><?php echo $loc_mws; ?></div>
                            <div class="status-unit">MWs</div>
                        </div>
                        <?php
                                    endforeach;
                                }
                            }

                            // Fallback: aggregate box for non-split statuses, or when no detailed breakdown exists
                            if (!$rendered_split):
                                $truckloads = $trucks_from($pallets, $modules);
                                $mws = $mw_from_modules($modules);
                                $clickHandler = $isAdmin ? "showShippingBreakdown('{$status_key}')" : "showCustomerShippingModal('{$status_key}')";
                        ?>
                        <div class="shipping-box <?php echo $status_info["class"]; ?>"
                             onclick="<?php echo $clickHandler; ?>"
                             data-pallets="<?php echo $pallets; ?>"
                             data-modules="<?php echo $modules; ?>"
                             data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                             data-mws="<?php echo $mws; ?>">
                            <div class="status-label"><?php echo $status_info["label"]; ?></div>
                            <div class="status-count"><?php echo $mws; ?></div>
                            <div class="status-unit">MWs</div>
                        </div>
                        <?php
                            endif;
                        endforeach;

                        // Exceptions
                        $exceptions_pallets = ($status_totals["Damaged"]["pallets"] ?? 0);
                        $exceptions_modules = ($status_totals["Damaged"]["modules"] ?? 0);
                        if ($exceptions_pallets > 0):
                            $truckloads = null;
                            if (!empty($average_pallets_per_truck)) {
                                $truckloads = round($exceptions_pallets / $average_pallets_per_truck, 1);
                            }
                            $mws = 0;
                            if (!empty($wattages) && $exceptions_modules > 0) {
                                $total_watts = array_sum($wattages);
                                $avg_wattage = count($wattages) > 0 ? ($total_watts / count($wattages)) : 0;
                                $mws = round(($exceptions_modules * $avg_wattage) / 1000000, 2);
                            }
                            $clickHandler = $isAdmin ? "showShippingBreakdown('Exceptions')" : "showCustomerShippingModal('Exceptions')";
                        ?>
                        <div class="shipping-box exceptions"
                             onclick="<?php echo $clickHandler; ?>"
                             data-pallets="<?php echo $exceptions_pallets; ?>"
                             data-modules="<?php echo $exceptions_modules; ?>"
                             data-truckloads="<?php echo ($truckloads !== null ? $truckloads : ''); ?>"
                             data-mws="<?php echo $mws; ?>">
                            <div class="status-label">Exceptions</div>
                            <div class="status-count"><?php echo $mws; ?></div>
                            <div class="status-unit">MWs</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </li>

                <!-- Step 5: Delivery Progress -->
                <li class="timeline-item timeline-progress-step<?php echo $step5_completed ? ' completed' : ''; ?><?php echo $current_step == 5 ? ' current' : ''; ?>">
                    <!-- SVG Gradient Definition -->
                    <svg style="position: absolute; width: 0; height: 0;">
                        <defs>
                            <linearGradient id="completionGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                            </linearGradient>
                            <linearGradient id="timelineProgressGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" style="stop-color:#488C9A;stop-opacity:1" />
                                <stop offset="100%" style="stop-color:#3A6E7F;stop-opacity:1" />
                            </linearGradient>
                        </defs>
                    </svg>

                    <div class="completion-hub">
                        <div class="completion-hub-inner">
                            <span class="percentage"><?php echo $project_completion_percentage; ?>%</span>
                            <span class="percentage-label">Delivered</span>
                        </div>
                        <svg class="progress-ring" viewBox="0 0 100 100">
                            <circle class="progress-ring-track" cx="50" cy="50" r="45"></circle>
                            <circle class="progress-ring-fill" cx="50" cy="50" r="45" style="--progress: <?php echo $project_completion_percentage; ?>"></circle>
                        </svg>
                    </div>

                    <span class="label">
                        <?php if ($step5_completed): ?>
                            Deliveries Complete
                        <?php else: ?>
                            Delivery Progress
                        <?php endif; ?>
                    </span>
                    <div class="description timeline-progress-details">
                        <?php
                        if ($step5_completed) {
                            echo "All modules delivered and project finalized";
                        } else {
                            $remaining_mw = $project_size_mw - $delivered_mw;
                            echo '<div style="font-size: 11px; line-height: 1.5;">';
                            echo '<div>' . number_format($delivered_mw, 2) . ' / ' . number_format($project_size_mw, 2) . ' MW delivered</div>';
                            if ($ordered_mw > 0 && $ordered_mw < $project_size_mw) {
                                echo '<div style="color: #856404;">(' . number_format($ordered_mw, 2) . ' MW ordered, ' . $ordered_vs_target_percentage . '% of target)</div>';
                            } elseif ($ordered_mw >= $project_size_mw) {
                                echo '<div style="color: #155724;">(' . $delivered_percentage . '% of ordered delivered)</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>

                    <?php if (!$step5_completed && !empty($est_completion_date_formatted)): ?>
                    <div class="timeline-health-indicator health-<?php echo $project_health; ?>">
                        <?php if ($project_health === 'at_risk'): ?>
                            <div class="health-badge at-risk">
                                <span class="health-icon">⚠️</span>
                                <span class="health-label">At Risk</span>
                            </div>
                            <div class="health-detail"><?php echo htmlspecialchars($project_health_reason); ?></div>
                        <?php elseif ($project_health === 'behind'): ?>
                            <div class="health-badge behind">
                                <span class="health-icon">🚨</span>
                                <span class="health-label">Behind Schedule</span>
                            </div>
                            <div class="health-detail"><?php echo htmlspecialchars($project_health_reason); ?></div>
                        <?php else: ?>
                            <div class="health-target">
                                <span class="target-icon">📅</span>
                                <span class="target-text">Target: <?php echo $est_completion_date_formatted; ?></span>
                                <?php if ($days_remaining !== null && $days_remaining > 0): ?>
                                <span class="days-remaining">(<?php echo $days_remaining; ?> days)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>

<!-- Site Tab -->
<div id="tab-site" class="tab-content">
        <div class="info-container">
            <div class="header-with-button">
                <h2>Site Information</h2>
                <?php if ($isGlobalAdmin): ?>
                    <button onclick="window.location.href='edit_project.php?project_id=<?php echo $project_id; ?>'" class="info-action-button" style="margin: 0;">
                        Edit Site Information
                    </button>
                <?php endif; ?>
            </div>
            <div class="info-grid">
                <div class="info-section">
                    <h3>Project Details</h3>
                    <div class="info-item">
                        <label>Project Name:</label>
                        <span><?php echo htmlspecialchars($project['project_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Project Address:</label>
                        <span><?php echo htmlspecialchars($project['project_address'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Project Size:</label>
                        <span><?php echo number_format($project_size_mw, 2); ?> MW</span>
                    </div>
                    <div class="info-item">
                        <label>Target Completion:</label>
                        <span><?php echo !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'Not specified'; ?></span>
                    </div>
                </div>
                <div class="info-section">
                    <h3>Site Contact</h3>
                    <div class="info-item">
                        <label>Contact Name:</label>
                        <span><?php echo htmlspecialchars($project['site_contact_name'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Contact Email:</label>
                        <span><?php echo !empty($project['site_contact_email']) ? '<a href="mailto:'.htmlspecialchars($project['site_contact_email']).'">'.htmlspecialchars($project['site_contact_email']).'</a>' : 'Not specified'; ?></span>
                    </div>
                    <div class="info-item">
                        <label>Contact Phone:</label>
                        <span><?php echo !empty($project['site_contact_phone']) ? '<a href="tel:'.htmlspecialchars($project['site_contact_phone']).'">'.htmlspecialchars($project['site_contact_phone']).'</a>' : 'Not specified'; ?></span>
                    </div>
                </div>
                <div class="info-section">
                    <h3>Receiving Schedule</h3>
                    <div class="info-item">
                        <label>Timezone:</label>
                        <?php
                        $timezone_labels = [
                            'America/New_York' => 'Eastern',
                            'America/Chicago' => 'Central',
                            'America/Denver' => 'Mountain',
                            'America/Los_Angeles' => 'Pacific',
                            'UTC' => 'UTC'
                        ];
                        $tz = $project['timezone'] ?? 'America/New_York';
                        ?>
                        <span><?php echo $timezone_labels[$tz] ?? $tz; ?></span>
                    </div>
                    <div class="info-item">
                        <label>Operating Hours:</label>
                        <?php
                        $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        if (!empty($site_operating_hours)):
                            // Check if all weekdays have the same hours
                            $weekday_hours = [];
                            for ($d = 1; $d <= 5; $d++) {
                                if (isset($site_operating_hours[$d])) {
                                    $weekday_hours[] = $site_operating_hours[$d]['start'] . '-' . $site_operating_hours[$d]['end'];
                                }
                            }
                            $all_same = count(array_unique($weekday_hours)) === 1 && count($weekday_hours) === 5;

                            if ($all_same):
                                $start = date('g:i A', strtotime($site_operating_hours[1]['start']));
                                $end = date('g:i A', strtotime($site_operating_hours[1]['end']));
                        ?>
                        <span>Mon-Fri: <?php echo $start; ?> - <?php echo $end; ?></span>
                        <?php else: ?>
                        <span style="font-size: 0.9em;">
                            <?php
                            $hours_display = [];
                            foreach ($site_operating_hours as $day => $times) {
                                $start = date('g:i A', strtotime($times['start']));
                                $end = date('g:i A', strtotime($times['end']));
                                $hours_display[] = $day_names[$day] . ': ' . $start . ' - ' . $end;
                            }
                            echo implode('<br>', $hours_display);
                            ?>
                        </span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span>Not specified</span>
                        <?php endif; ?>
                    </div>
                    <div class="info-item">
                        <label>Appointment Window:</label>
                        <?php
                        $appt_mins = $project['appointment_duration'] ?? 30;
                        if ($appt_mins >= 60) {
                            $appt_display = ($appt_mins / 60) . ' hour' . ($appt_mins > 60 ? 's' : '');
                        } else {
                            $appt_display = $appt_mins . ' minutes';
                        }
                        ?>
                        <span><?php echo $appt_display; ?></span>
                    </div>
                </div>
            </div>

            <?php if (!empty($project['instructions']) || !empty($project['additional_notes'])): ?>
            <div class="info-grid" style="margin-top: 20px;">
                <?php if (!empty($project['instructions'])): ?>
                <div class="info-section">
                    <h3>Special Instructions</h3>
                    <div class="info-item" style="flex-direction: column; align-items: flex-start;">
                        <span style="white-space: pre-wrap;"><?php echo htmlspecialchars($project['instructions']); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($project['additional_notes'])): ?>
                <div class="info-section">
                    <h3>Additional Notes</h3>
                    <div class="info-item" style="flex-direction: column; align-items: flex-start;">
                        <span style="white-space: pre-wrap;"><?php echo htmlspecialchars($project['additional_notes']); ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Site Documents Section -->
            <div class="info-grid" style="margin-top: 20px;">
                <div class="info-section" style="grid-column: 1 / -1;">
                    <h3>
                        <i class="fas fa-file-alt" style="color: #488C9A; margin-right: 8px;"></i>Site Documents
                        <a href="project_documents.php?project_id=<?php echo $project_id; ?>&filter_type=site" id="site-docs-count-link" class="docs-count-link" style="display: none;"></a>
                    </h3>
                    <div id="site-docs-list" class="docs-grid">
                        <div class="docs-loading"><i class="fas fa-spinner fa-spin"></i> Loading documents...</div>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                const projectId = <?php echo $project_id; ?>;
                fetch(`get_project_documents.php?project_id=${projectId}&document_type_in=site`)
                    .then(r => r.json())
                    .then(data => {
                        const container = document.getElementById('site-docs-list');
                        const countLink = document.getElementById('site-docs-count-link');

                        if (!data.success || !data.documents || data.documents.length === 0) {
                            container.innerHTML = '<div class="docs-empty"><i class="fas fa-folder-open"></i><span>No site documents uploaded yet</span></div>';
                            return;
                        }

                        // Update count link
                        countLink.textContent = `(${data.documents.length})`;
                        countLink.style.display = 'inline';

                        let html = '';
                        data.documents.forEach(doc => {
                            const ext = (doc.original_name || '').split('.').pop().toLowerCase();
                            let iconClass = 'fas fa-file';
                            let iconColor = '#6c757d';
                            if (['pdf'].includes(ext)) { iconClass = 'fas fa-file-pdf'; iconColor = '#dc3545'; }
                            else if (['doc', 'docx'].includes(ext)) { iconClass = 'fas fa-file-word'; iconColor = '#2b579a'; }
                            else if (['xls', 'xlsx'].includes(ext)) { iconClass = 'fas fa-file-excel'; iconColor = '#217346'; }
                            else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) { iconClass = 'fas fa-file-image'; iconColor = '#488C9A'; }

                            const isPreviewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif'].includes(ext);

                            html += `
                                <div class="doc-item" onclick="openDocPreview('${doc.file_path}', '${(doc.original_name || 'Document').replace(/'/g, "\\'")}', '${ext}')" style="cursor: pointer;">
                                    <i class="${iconClass}" style="color: ${iconColor};"></i>
                                    <div class="doc-details">
                                        <span class="doc-name" title="${doc.original_name || 'Document'}">${doc.original_name || 'Document'}</span>
                                        <span class="doc-subtype">${doc.document_sub_type || 'Site Document'}</span>
                                    </div>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    })
                    .catch(err => {
                        document.getElementById('site-docs-list').innerHTML = '<div class="docs-error"><i class="fas fa-exclamation-triangle"></i> Error loading documents</div>';
                    });
            })();
            </script>
        </div>
    </div>

<!-- Modules Tab -->
<div id="tab-modules" class="tab-content">
        <div class="info-container">
            <div class="header-with-button">
                <h2>Module Information</h2>
                <?php if ($isGlobalAdmin): ?>
                <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="info-action-button" style="margin:0; padding:10px 16px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-plus"></i> Add Module Batch
                </a>
                <?php endif; ?>
            </div>

            <?php if ($has_milestone_data): ?>
            <!-- Milestone Payment Progress Section -->
            <div class="module-batch-section" style="background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); border: 1px solid #e9ecef;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 class="batch-title" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-coins" style="color: #17a2b8;"></i>
                        Module Payment Milestones
                    </h3>
                    <?php
                    $completion_percent = $milestone_completion['completion_percent'] ?? 0;
                    $progress_color = '#17a2b8';
                    if ($completion_percent >= 100) {
                        $progress_color = '#28a745';
                    } elseif ($completion_percent >= 75) {
                        $progress_color = '#17a2b8';
                    } elseif ($completion_percent >= 50) {
                        $progress_color = '#ffc107';
                    }
                    ?>
                    <span style="font-size: 1.25em; font-weight: 700; color: <?php echo $progress_color; ?>;">
                        <?php echo number_format($completion_percent, 0); ?>% Triggered
                    </span>
                </div>

                <!-- Progress Bar -->
                <div style="background: #e9ecef; border-radius: 8px; height: 10px; overflow: hidden; margin-bottom: 16px;">
                    <div style="background: linear-gradient(90deg, <?php echo $progress_color; ?> 0%, <?php echo $progress_color; ?>dd 100%); height: 100%; width: <?php echo min($completion_percent, 100); ?>%; border-radius: 8px; transition: width 0.5s ease;"></div>
                </div>

                <!-- Milestone Details -->
                <div class="info-grid" style="margin-bottom: 16px;">
                    <?php foreach ($module_milestone_rows as $msr): ?>
                        <?php if ($msr['percentage'] <= 0) continue; ?>
                        <div class="info-section" style="padding: 12px; background: #fff; border-radius: 8px; border: 1px solid #e9ecef;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <div style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $msr['is_complete'] ? '#28a745' : '#e9ecef'; ?>; border: 2px solid <?php echo $msr['is_complete'] ? '#28a745' : '#adb5bd'; ?>;"></div>
                                <span style="font-weight: 600; color: #293E4C;"><?php echo htmlspecialchars($msr['name']); ?></span>
                                <span style="font-size: 0.85em; color: #6c757d;">(<?php echo $msr['percentage']; ?>%)</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9em;">
                                <span style="color: <?php echo $msr['is_complete'] ? '#28a745' : '#488C9A'; ?>; font-weight: 600;">$<?php echo number_format($msr['accrued_amount'], 0); ?></span>
                                <span style="color: #6c757d;">of $<?php echo number_format($msr['target_amount'], 0); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Summary Stats -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; padding-top: 16px; border-top: 1px solid #e9ecef;">
                    <div style="text-align: center;">
                        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Contract Value</div>
                        <div style="font-size: 1.1em; font-weight: 600; color: #293E4C;">$<?php echo number_format($milestone_completion['total_contract_value'], 0); ?></div>
                    </div>
                    <div style="text-align: center; border-left: 1px solid #e9ecef; border-right: 1px solid #e9ecef;">
                        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Triggered</div>
                        <div style="font-size: 1.1em; font-weight: 600; color: #28a745;">$<?php echo number_format($milestone_completion['total_triggered'], 0); ?></div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Remaining</div>
                        <div style="font-size: 1.1em; font-weight: 600; color: #6c757d;">$<?php echo number_format($milestone_completion['total_remaining'], 0); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($module_batches)): ?>
                <?php foreach ($module_batches as $batch):
                    // Calculate total modules for this batch
                    $batch_total_modules = 0;
                    if (!empty($batch['wattages'])) {
                        foreach ($batch['wattages'] as $w) {
                            $batch_total_modules += $w['quantity'];
                        }
                    }
                    // Build wattage summary string
                    $wattage_summary = '';
                    if (!empty($batch['wattages'])) {
                        $wattage_labels = array_map(function($w) {
                            return $w['wattage'] . 'W';
                        }, $batch['wattages']);
                        $wattage_summary = implode(', ', $wattage_labels);
                    }
                ?>
                    <div class="module-batch-section" style="padding: 16px 20px;">
                        <!-- Row 1: Batch title + Edit button -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <h3 class="batch-title" style="margin: 0;">
                                <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch'); ?>
                                <?php if (!empty($batch['is_replacement_batch'])): ?>
                                    <span class="batch-badge">Replacement</span>
                                <?php endif; ?>
                            </h3>
                            <?php if ($isGlobalAdmin): ?>
                            <a href="edit_module_batch.php?batch_id=<?php echo $batch['id']; ?>" class="batch-edit-btn" title="Edit <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Batch'); ?>" style="background:#fff; color:#488C9A; border:1px solid #488C9A; padding:6px 12px; border-radius:6px; font-size:0.85em; text-decoration:none; display:inline-flex; align-items:center; gap:4px; transition: all 0.2s ease;" onmouseover="this.style.background='#488C9A';this.style.color='#fff';" onmouseout="this.style.background='#fff';this.style.color='#488C9A';">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Row 2: Compact stat pills -->
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px;">
                            <span style="display: inline-flex; align-items: center; gap: 5px; background: #f0f7f8; color: #293E4C; padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 500;">
                                <i class="fas fa-solar-panel" style="color: #488C9A; font-size: 0.85em;"></i>
                                <?php echo number_format($batch_total_modules); ?> Modules
                            </span>
                            <?php if ($wattage_summary): ?>
                            <span style="display: inline-flex; align-items: center; gap: 5px; background: #f0f7f8; color: #293E4C; padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 500;">
                                <i class="fas fa-bolt" style="color: #f0ad4e; font-size: 0.85em;"></i>
                                <?php echo $wattage_summary; ?>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($batch['modules_per_pallet'])): ?>
                            <span style="display: inline-flex; align-items: center; gap: 5px; background: #f0f7f8; color: #293E4C; padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 500;">
                                <i class="fas fa-boxes" style="color: #6c757d; font-size: 0.85em;"></i>
                                <?php echo $batch['modules_per_pallet']; ?>/pallet
                            </span>
                            <?php endif; ?>
                            <span id="batch-doc-pill-<?php echo $batch['id']; ?>" style="display: none; align-items: center; gap: 5px; background: #f0f7f8; color: #293E4C; padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 500;">
                                <i class="fas fa-file-alt" style="color: #488C9A; font-size: 0.85em;"></i>
                                <span class="doc-count-text"></span>
                            </span>
                        </div>

                        <!-- Row 3: View Details button -->
                        <div>
                            <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="info-action-button" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 0.9em;">
                                View Details <i class="fas fa-arrow-right" style="font-size: 0.8em;"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <!-- Single script to fetch doc counts for all batches -->
                <script>
                (function() {
                    const projectId = <?php echo $project_id; ?>;
                    const batchIds = <?php echo json_encode(array_column($module_batches, 'id')); ?>;

                    fetch(`get_project_documents.php?project_id=${projectId}&document_type_in=modules`)
                        .then(r => r.json())
                        .then(data => {
                            if (!data.success || !data.documents) return;

                            batchIds.forEach(batchId => {
                                const count = data.documents.filter(doc =>
                                    doc.module_id == batchId || !doc.module_id
                                ).length;

                                const pill = document.getElementById('batch-doc-pill-' + batchId);
                                if (pill && count > 0) {
                                    pill.querySelector('.doc-count-text').textContent = count + ' Doc' + (count !== 1 ? 's' : '');
                                    pill.style.display = 'inline-flex';
                                }
                            });
                        })
                        .catch(() => {});
                })();
                </script>
            <?php else: ?>
                <div class="no-data-message">
                    <p>No module batches have been added to this project yet.</p>
                    <?php if ($isGlobalAdmin): ?>
                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="info-action-button">
                            + Add Module Batch
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<!-- Deliveries Tab -->
<div id="tab-deliveries" class="tab-content">
        <!-- Unit Filters (inside content) -->
        <div class="unit-filter-bar">
            <span class="filter-label">View as:</span>
            <div class="filter-chips">
                <button type="button" class="filter-chip active" data-unit="mws">MWs</button>
                <button type="button" class="filter-chip" data-unit="modules">Modules</button>
                <button type="button" class="filter-chip" data-unit="pallets">Pallets</button>
                <button type="button" class="filter-chip" data-unit="truckloads">Trucks</button>
            </div>
        </div>

        <?php if (!$has_projection): ?>
        <div class="analytics-note" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fff8e1 0%, #fffde7 100%); border: 1px solid #ffe082; border-radius: 8px; margin-bottom: 16px; font-size: 0.9em;">
            <i class="fas fa-info-circle" style="color: #f9a825;"></i>
            <span style="color: #5d4037;">Delivery projections are set in <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>" style="color: #488C9A; font-weight: 500;">Project Planning</a>. Add a plan to see forecasted delivery data.</span>
        </div>
        <?php endif; ?>

        <!-- Module Flow — 3-column journey -->
        <div class="flow-card">
            <div class="flow-header">
                <h2>Module Flow</h2>
                <span class="flow-caption">
                    <?php echo number_format($flow_total_raw); ?> modules tracked
                    <?php if ($flow_total_raw > 0): ?>
                        · <span class="flow-caption-pct"><?php echo number_format($flow_pct_delivered, 1); ?>% delivered to site</span>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($flow_total_raw <= 0): ?>
                <div class="flow-empty">
                    <i class="fas fa-boxes"></i>
                    <p>No module flow data yet for this project.</p>
                </div>
            <?php else: ?>
            <div class="flow-3col">
                <!-- ORIGIN COLUMN -->
                <?php $origin_is_warehouse = ($flow_origin['type'] ?? 'manufacturer') === 'warehouse'; ?>
                <div class="flow-col flow-col-origin">
                    <div class="flow-col-header">
                        <i class="fas <?php echo $origin_is_warehouse ? 'fa-warehouse' : 'fa-industry'; ?>"></i>
                        Origin
                    </div>
                    <div class="flow-node origin-node<?php echo ($origin_is_warehouse && !empty($flow_origin['is_empty'])) ? ' stop-passed-through' : ''; ?>">
                        <?php if ($origin_is_warehouse): ?>
                            <div class="flow-node-title"><?php echo htmlspecialchars($flow_origin['name']); ?></div>
                            <div class="flow-node-subtitle">First Tracked</div>
                        <?php else: ?>
                            <div class="flow-node-title">
                                <?php
                                $mfr_names = $flow_origin['manufacturer_names'] ?? [];
                                if (count($mfr_names) === 0)      echo 'Manufacturer';
                                elseif (count($mfr_names) === 1)  echo htmlspecialchars($mfr_names[0]);
                                elseif (count($mfr_names) <= 3)   echo htmlspecialchars(implode(', ', $mfr_names));
                                else                              echo htmlspecialchars(count($mfr_names) . ' manufacturers');
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="flow-node-stats">
                            <div class="flow-stat-primary">
                                <span class="flow-stat-value" data-flow-raw="<?php echo (int)$flow_origin['modules']; ?>">—</span>
                                <span class="flow-stat-unit" data-flow-unit-suffix></span>
                            </div>
                            <div class="flow-stat-pallets"><?php echo number_format($flow_origin['pallets']); ?> pallets</div>
                        </div>
                        <?php if (!$origin_is_warehouse && (($flow_origin['on_water_modules'] ?? 0) > 0 || ($flow_origin['customs_modules'] ?? 0) > 0)): ?>
                            <div class="flow-node-tags">
                                <?php if (($flow_origin['on_water_modules'] ?? 0) > 0): ?>
                                    <span class="flow-tag tag-water"><i class="fas fa-ship"></i> <span data-flow-raw="<?php echo (int)$flow_origin['on_water_modules']; ?>">—</span> on water</span>
                                <?php endif; ?>
                                <?php if (($flow_origin['customs_modules'] ?? 0) > 0): ?>
                                    <span class="flow-tag tag-customs"><i class="fas fa-flag"></i> <span data-flow-raw="<?php echo (int)$flow_origin['customs_modules']; ?>">—</span> in customs</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ORIGIN → STOPS CONNECTOR -->
                <div class="flow-arrow flow-arrow-h<?php echo $flow_in_transit_to_warehouse_modules > 0 ? ' flow-arrow-active' : ''; ?>">
                    <div class="flow-arrow-line"></div>
                    <?php if ($flow_in_transit_to_warehouse_modules > 0): ?>
                        <span class="flow-arrow-label">
                            <i class="fas fa-truck-moving"></i>
                            <span data-flow-raw="<?php echo (int)$flow_in_transit_to_warehouse_modules; ?>">—</span>
                            in transit
                        </span>
                    <?php endif; ?>
                </div>

                <!-- STOPS COLUMN -->
                <div class="flow-col flow-col-stops">
                    <div class="flow-col-header"><i class="fas fa-warehouse"></i> Stops</div>
                    <?php if (empty($flow_stops_visible) && empty($flow_wh_to_wh_moves)): ?>
                        <div class="flow-node flow-node-empty stop-node">
                            <div class="flow-node-title-muted">No active warehouse stops</div>
                        </div>
                    <?php else: ?>
                        <div class="flow-stops-list">
                            <?php foreach ($flow_stops_visible as $idx => $stop): ?>
                                <?php
                                $is_last_visible = ($idx === count($flow_stops_visible) - 1);
                                $next_stop_id = $is_last_visible ? null : $flow_stops_visible[$idx + 1]['warehouse_id'];
                                $w2w_to_next = null;
                                if (!$is_last_visible) {
                                    foreach ($flow_wh_to_wh_moves as $mv) {
                                        if ((int)$mv['from_warehouse_id'] === (int)$stop['warehouse_id']
                                            && (int)$mv['to_warehouse_id'] === (int)$next_stop_id) {
                                            $w2w_to_next = $mv;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <div class="flow-node stop-node<?php echo !empty($stop['is_empty']) ? ' stop-passed-through' : ''; ?>" data-warehouse-id="<?php echo (int)$stop['warehouse_id']; ?>">
                                    <div class="flow-node-title"><?php echo htmlspecialchars($stop['name']); ?></div>
                                    <?php if (!empty($stop['is_empty'])): ?>
                                        <div class="flow-node-subtitle">Passed through</div>
                                    <?php endif; ?>
                                    <div class="flow-node-stats">
                                        <div class="flow-stat-primary">
                                            <span class="flow-stat-value" data-flow-raw="<?php echo (int)$stop['modules']; ?>">—</span>
                                            <span class="flow-stat-unit" data-flow-unit-suffix></span>
                                        </div>
                                        <div class="flow-stat-pallets"><?php echo number_format($stop['pallets']); ?> pallets</div>
                                    </div>
                                </div>
                                <?php if ($w2w_to_next): ?>
                                    <div class="flow-stop-connector">
                                        <i class="fas fa-arrow-down"></i>
                                        <span class="flow-stop-connector-text">
                                            <span data-flow-raw="<?php echo (int)$w2w_to_next['modules']; ?>">—</span>
                                            moving to <?php echo htmlspecialchars($w2w_to_next['to_name']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if ($flow_stops_overflow > 0): ?>
                                <a class="flow-stops-overflow" href="module_movements.php?project_id=<?php echo $project_id; ?>" title="View all warehouses on the movements page">
                                    +<?php echo (int)$flow_stops_overflow; ?> more warehouse<?php echo $flow_stops_overflow === 1 ? '' : 's'; ?>
                                    · <span data-flow-raw="<?php echo (int)$flow_stops_overflow_modules; ?>">—</span>
                                </a>
                            <?php endif; ?>

                            <?php
                            // Surface any WH→WH transfers that aren't already drawn between adjacent visible stops.
                            $rendered_w2w = [];
                            foreach ($flow_stops_visible as $idx => $stop) {
                                if ($idx === count($flow_stops_visible) - 1) break;
                                $next_id = $flow_stops_visible[$idx + 1]['warehouse_id'];
                                foreach ($flow_wh_to_wh_moves as $mv) {
                                    if ((int)$mv['from_warehouse_id'] === (int)$stop['warehouse_id']
                                        && (int)$mv['to_warehouse_id'] === (int)$next_id) {
                                        $rendered_w2w[] = $mv['from_warehouse_id'] . '->' . $mv['to_warehouse_id'];
                                    }
                                }
                            }
                            $extra_moves = array_filter($flow_wh_to_wh_moves, function($mv) use ($rendered_w2w) {
                                return !in_array($mv['from_warehouse_id'] . '->' . $mv['to_warehouse_id'], $rendered_w2w, true);
                            });
                            ?>
                            <?php foreach ($extra_moves as $mv): ?>
                                <div class="flow-stop-connector flow-stop-connector-extra">
                                    <i class="fas fa-truck-moving"></i>
                                    <span class="flow-stop-connector-text">
                                        <span data-flow-raw="<?php echo (int)$mv['modules']; ?>">—</span>
                                        moving <?php echo htmlspecialchars($mv['from_name']); ?> → <?php echo htmlspecialchars($mv['to_name']); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- STOPS → DESTINATION CONNECTOR -->
                <div class="flow-arrow flow-arrow-h<?php echo $flow_in_transit_to_project_modules > 0 ? ' flow-arrow-active' : ''; ?>">
                    <div class="flow-arrow-line"></div>
                    <?php if ($flow_in_transit_to_project_modules > 0): ?>
                        <span class="flow-arrow-label">
                            <i class="fas fa-truck"></i>
                            <span data-flow-raw="<?php echo (int)$flow_in_transit_to_project_modules; ?>">—</span>
                            in transit
                        </span>
                    <?php endif; ?>
                </div>

                <!-- DESTINATION COLUMN -->
                <div class="flow-col flow-col-destination">
                    <div class="flow-col-header"><i class="fas fa-flag-checkered"></i> Destination</div>
                    <div class="flow-node destination-node">
                        <div class="flow-node-title"><?php echo htmlspecialchars($flow_destination['project_name']); ?></div>
                        <div class="flow-node-subtitle">Project Site</div>
                        <div class="flow-node-stats">
                            <div class="flow-stat-primary">
                                <span class="flow-stat-value" data-flow-raw="<?php echo (int)$flow_destination['modules']; ?>">—</span>
                                <span class="flow-stat-unit" data-flow-unit-suffix></span>
                            </div>
                            <div class="flow-stat-pallets"><?php echo number_format($flow_destination['pallets']); ?> pallets</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="flow-card-footer">
                <a href="module_movements.php?project_id=<?php echo $project_id; ?>" class="flow-card-footer-link">
                    <i class="fas fa-map-marked-alt"></i> View on map
                </a>
            </div>
        </div>

        <div class="deliveries-grid">
            <!-- LEFT: Delivery forecast chart + Next Up -->
            <div class="deliveries-card forecast-card">
                <div class="cashflow-header">
                    <h2>Delivery Forecast</h2>
                    <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>" class="view-all-link" title="Data from Project Planning"><?php echo $has_projection ? 'View Plan' : 'Add Plan'; ?></a>
                </div>
                <div class="forecast-chart-wrapper">
                    <canvas id="lineChart"></canvas>
                    <div class="chart-no-data" id="lineChartNoData" style="display:none;">
                        <i class="fas fa-chart-line"></i>
                        <p>No delivery data available yet</p>
                    </div>
                </div>

                <h3 class="activity-list-title"><i class="fas fa-arrow-right"></i> Next Up</h3>
                <?php if (empty($next_deliveries)): ?>
                    <div class="activity-list-empty">No scheduled deliveries on the books.</div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php
                        $today_dt = new DateTime('today');
                        foreach ($next_deliveries as $nd):
                            $dest = $nd['dest_warehouse_name'] ?: 'Project Site';
                            $origin = ($nd['origin_type'] === 'warehouse' && $nd['origin_warehouse_name'])
                                      ? $nd['origin_warehouse_name']
                                      : 'Manufacturer';
                            $eta = new DateTime($nd['anticipated_delivery_date']);
                            $diff = (int)$today_dt->diff($eta)->format('%r%a');
                            if ($diff < 0)       $rel = abs($diff) . ' day' . (abs($diff)===1?'':'s') . ' overdue';
                            elseif ($diff === 0) $rel = 'today';
                            elseif ($diff === 1) $rel = 'tomorrow';
                            else                 $rel = 'in ' . $diff . ' days';
                            $qty_raw = (int)($nd['qty'] ?? 0);
                            $pallet_count = (int)($nd['pallet_count'] ?? 0);
                        ?>
                            <?php $ship_count = (int)($nd['shipment_count'] ?? 1); ?>
                            <li class="activity-row">
                                <div class="activity-route">
                                    <span class="route-origin"><?php echo htmlspecialchars($origin); ?></span>
                                    <i class="fas fa-arrow-right route-arrow"></i>
                                    <span class="route-dest"><?php echo htmlspecialchars($dest); ?></span>
                                    <?php if ($ship_count > 1): ?>
                                        <span class="activity-pill activity-pill-count"><?php echo $ship_count; ?> shipments</span>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <span class="activity-qty">
                                        <span data-flow-raw="<?php echo $qty_raw; ?>">—</span>
                                        <span data-flow-unit-suffix></span>
                                    </span>
                                    <?php if ($pallet_count > 0): ?>
                                        <span class="activity-meta-sep">·</span>
                                        <span class="activity-pallets"><?php echo number_format($pallet_count); ?> pallet<?php echo $pallet_count===1?'':'s'; ?></span>
                                    <?php endif; ?>
                                    <span class="activity-when"><?php echo $eta->format('M j'); ?> · <?php echo $rel; ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Recent Activity (last 5 completed events) -->
            <div class="deliveries-card recent-card">
                <div class="cashflow-header">
                    <h2>Recent Activity</h2>
                    <a href="project_overview.php?project_id=<?php echo $project_id; ?>#tab-timeline" class="view-all-link">View Timeline</a>
                </div>
                <?php if (empty($recent_activity)): ?>
                    <div class="activity-list-empty" style="padding-top: 24px;">No completed shipments yet.</div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php
                        $today_dt = $today_dt ?? new DateTime('today');
                        foreach ($recent_activity as $rev):
                            $is_final = ($rev['status_of_delivery'] === 'Delivered to Project');
                            $dest_name = $is_final
                                ? ($flow_destination['project_name'] ?? 'Project Site')
                                : ($rev['dest_warehouse_name'] ?: 'Warehouse');
                            $origin_name = ($rev['origin_type'] === 'warehouse' && $rev['origin_warehouse_name'])
                                ? $rev['origin_warehouse_name']
                                : 'Manufacturer';
                            $event_date = new DateTime($rev['event_date']);
                            $diff = (int)$event_date->diff($today_dt)->format('%r%a');
                            if ($diff <= 0)      $rel = 'today';
                            elseif ($diff === 1) $rel = 'yesterday';
                            elseif ($diff < 7)   $rel = $diff . ' days ago';
                            elseif ($diff < 30)  $rel = floor($diff/7) . 'w ago';
                            else                 $rel = $event_date->format('M j');
                            $qty_raw = (int)($rev['qty'] ?? 0);
                            $pallet_count = (int)($rev['pallet_count'] ?? 0);
                        ?>
                            <?php $ship_count = (int)($rev['shipment_count'] ?? 1); ?>
                            <li class="activity-row activity-row-completed<?php echo $is_final ? ' activity-row-final' : ''; ?>">
                                <div class="activity-route">
                                    <span class="route-origin"><?php echo htmlspecialchars($origin_name); ?></span>
                                    <i class="fas fa-arrow-right route-arrow"></i>
                                    <span class="route-dest"><?php echo htmlspecialchars($dest_name); ?></span>
                                    <?php if ($ship_count > 1): ?>
                                        <span class="activity-pill activity-pill-count"><?php echo $ship_count; ?> shipments</span>
                                    <?php endif; ?>
                                    <?php if ($is_final): ?>
                                        <span class="activity-pill"><i class="fas fa-flag-checkered"></i> Delivered</span>
                                    <?php else: ?>
                                        <span class="activity-pill activity-pill-arrived"><i class="fas fa-check"></i> Arrived</span>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-meta">
                                    <span class="activity-qty">
                                        <span data-flow-raw="<?php echo $qty_raw; ?>">—</span>
                                        <span data-flow-unit-suffix></span>
                                    </span>
                                    <?php if ($pallet_count > 0): ?>
                                        <span class="activity-meta-sep">·</span>
                                        <span class="activity-pallets"><?php echo number_format($pallet_count); ?> pallet<?php echo $pallet_count===1?'':'s'; ?></span>
                                    <?php endif; ?>
                                    <span class="activity-when"><?php echo $event_date->format('M j'); ?> · <?php echo $rel; ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php if (false): ?>
<!-- LEGACY REFERENCE: Financial tab moved to project_cost_details.php. This block is dead code kept for reference only. Styles that were inline here (cashflow-header, view-all-link) have been moved to project_overview.css. -->
<div id="tab-financial" class="tab-content">
        <!-- Unit Filters (inside content) -->
        <div class="unit-filter-bar">
            <span class="filter-label">View as:</span>
            <div class="filter-chips">
                <button type="button" class="filter-chip cost-unit-chip active" data-unit="total" title="Total Cost">Total</button>
                <button type="button" class="filter-chip cost-unit-chip" data-unit="watt" title="Cost per Watt">$/W</button>
                <button type="button" class="filter-chip cost-unit-chip" data-unit="module" title="Cost per Module">$/Mod</button>
                <button type="button" class="filter-chip cost-unit-chip" data-unit="pallet" title="Cost per Pallet">$/Plt</button>
            </div>
        </div>

        <?php if (!$has_projection): ?>
        <div class="analytics-note" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fff8e1 0%, #fffde7 100%); border: 1px solid #ffe082; border-radius: 8px; margin-bottom: 16px; font-size: 0.9em;">
            <i class="fas fa-info-circle" style="color: #f9a825;"></i>
            <span style="color: #5d4037;">Cashflow forecasts are calculated from <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>" style="color: #488C9A; font-weight: 500;">Project Planning</a>. Add a plan to see forecasted financial data.</span>
        </div>
        <?php endif; ?>

        <style>
            .cashflow-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0; }
            .cashflow-header h2 { margin-bottom: 0; }
            .view-all-link { font-size: 0.85em; color: #488C9A; text-decoration: none; font-weight: 500; cursor: pointer; }
            .view-all-link:hover { text-decoration: underline; color: #3A6E7F; }
        </style>
        <div class="tables-and-charts">
                <div class="left-side">
                    <div class="cashflow-header">
                        <h2>Invoices and Cashflow Forecast</h2>
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>&view=weekly-projections" class="view-all-link">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table id="invoices-forecast-table">
                            <thead>
                                <tr>
                                    <th>Open Invoices</th>
                                    <th>Total Costs</th>
                                    <?php foreach($weeks_financial as $wf): ?>
                                        <th><?php echo $wf['end']->format('n/j'); ?></th>
                                    <?php endforeach;?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <a class="open-invoices-link" href="invoices_all.php?project_id=<?php echo $project_id; ?>">
                                            $<?php echo number_format($open_invoices_total,2);?>
                                        </a>
                                    </td>
                                    <?php
                                    $total_costs_display = $total_project_cost;
                                    $total_costs_ppw = $sum_watts > 0 ? $total_costs_display / $sum_watts : 0;
                                    $total_costs_ppm = $combined_qty > 0 ? $total_costs_display / $combined_qty : 0;
                                    $total_costs_ppp = $combined_pallets > 0 ? $total_costs_display / $combined_pallets : 0;
                                    ?>
                                    <td>
                                        <button type="button"
                                                class="financial-value total-cost-link"
                                                data-total="<?php echo $total_costs_display; ?>"
                                                data-watt="<?php echo $total_costs_ppw; ?>"
                                                data-module="<?php echo $total_costs_ppm; ?>"
                                                data-pallet="<?php echo $total_costs_ppp; ?>"
                                                onclick="openTotalCostBreakdownModal()">
                                            $<?php echo number_format($total_costs_display,2);?>
                                        </button>
                                    </td>
                                    <?php foreach($weeks_financial as $ix=>$wf){
                                        $val = $anticipated_deliveries_financial[$ix] ?? 0;
                                        // Calculate per-unit values for weekly forecasts
                                        $week_ppw = ($total_watts ?? 0) > 0 ? $val / ($total_watts ?? 1) : 0;
                                        $week_ppm = ($total_modules ?? 0) > 0 ? $val / ($total_modules ?? 1) : 0;
                                        $week_ppp = ($total_pallets ?? 0) > 0 ? $val / ($total_pallets ?? 1) : 0;
                                        echo "<td class=\"financial-value\" data-total=\"{$val}\" data-watt=\"{$week_ppw}\" data-module=\"{$week_ppm}\" data-pallet=\"{$week_ppp}\">$".number_format($val,2)."</td>";
                                    } ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="cashflow-header">
                        <h2>Forecasted vs Actual Cost</h2>
                        <a href="anticipated_deliveries.php?project_id=<?php echo $project_id; ?>&view=weekly-projections" class="view-all-link" title="Data from Project Planning"><?php echo $has_projection ? 'View Plan' : 'Add Plan'; ?></a>
                    </div>
                    <canvas id="budgetLineChart"></canvas>
                </div>

                <div class="right-side">
                    <style>
                        .cost-summary-table {
                            width: 100%;
                            border-collapse: collapse;
                        }
                        .cost-summary-table thead th {
                            background: #488C9A;
                            color: #fff;
                            padding: 12px 16px;
                            text-align: left;
                            font-size: 0.8em;
                            font-weight: 600;
                        }
                        .cost-summary-table tbody td {
                            padding: 14px 16px;
                            border-bottom: 1px solid #f1f3f4;
                            font-size: 0.9em;
                        }
                        .cost-summary-table .cost-label {
                            color: #293E4C;
                            font-weight: 500;
                        }
                        .cost-summary-table .cost-value {
                            font-weight: 700;
                            color: #488C9A;
                            text-align: right;
                        }
                        .cost-summary-table .logistics-link {
                            color: #488C9A;
                            cursor: pointer;
                            text-decoration: underline;
                            text-decoration-style: dotted;
                        }
                        .cost-summary-table .logistics-link:hover {
                            color: #3A6E7F;
                        }
                        .cost-summary-table tfoot td {
                            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
                            color: #fff;
                            font-weight: 700;
                            padding: 14px 16px;
                        }
                        .cost-summary-table tfoot .cost-value {
                            color: #fff;
                        }
                        .no-module-row {
                            color: #9ca3af;
                            font-style: italic;
                        }
                        .cost-chart-section {
                            margin-top: 24px;
                        }
                        .cost-chart-container {
                            display: flex;
                            align-items: center;
                            gap: 24px;
                            padding: 16px;
                            background: #fff;
                            border-radius: 8px;
                            border: 1px solid #e9ecef;
                        }
                        .cost-chart-canvas-lg { width: 220px; height: 220px; }
                        .cost-chart-legend-lg { flex: 1; }
                        .cost-legend-item-lg {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                            padding: 6px 0;
                        }
                        .cost-legend-dot-lg {
                            width: 14px; height: 14px;
                            border-radius: 3px;
                            flex-shrink: 0;
                        }
                        .cost-legend-dot-lg.module { background: #488C9A; }
                        .cost-legend-dot-lg.freight { background: #3b82f6; }
                        .cost-legend-dot-lg.warehousing { background: #8b5cf6; }
                        .cost-legend-dot-lg.other { background: #9ca3af; }
                        .cost-legend-info { flex: 1; }
                        .cost-legend-label { font-size: 0.85em; color: #293E4C; font-weight: 500; }
                        .cost-legend-value { font-size: 0.75em; color: #6c757d; }
                        .cost-legend-pct-lg { font-weight: 700; color: #488C9A; font-size: 0.9em; }
                    </style>

                    <div class="cashflow-header">
                        <h2>Project Cost Summary</h2>
                        <a href="project_cost_details.php?project_id=<?php echo $project_id; ?>" class="view-all-link">View Details</a>
                    </div>
                    <div class="table-responsive">
                        <table class="cost-summary-table">
                            <thead>
                                <tr>
                                    <th>Cost Type</th>
                                    <th style="text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $display_module_cost = $has_milestone_data ? $accrued_module_cost : $total_module_cost;
                                $module_cost_per_module = $total_raw_modules > 0 ? $display_module_cost / $total_raw_modules : 0;
                                $module_cost_per_pallet = $total_raw_modules > 0 ? ($display_module_cost / $total_raw_modules) * 30 : 0;
                                ?>
                                <?php if ($has_module_cost_data || $has_milestone_data): ?>
                                <tr>
                                    <td class="cost-label">
                                        <?php if ($has_milestone_data): ?>
                                        <span class="logistics-link" onclick="openModuleBreakdownModal()">Module Investment</span>
                                        <?php else: ?>
                                        Module Investment
                                        <?php endif; ?>
                                    </td>
                                    <td class="cost-value cost-value-dynamic" data-total="<?php echo $display_module_cost; ?>" data-watt="<?php echo $module_cost_per_watt ?? 0; ?>" data-module="<?php echo $module_cost_per_module; ?>" data-pallet="<?php echo $module_cost_per_pallet; ?>">$<?php echo number_format($display_module_cost, 2); ?></td>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <td class="cost-label no-module-row">Module Investment</td>
                                    <td class="cost-value no-module-row">N/A</td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="cost-label">
                                        <span class="logistics-link" onclick="openLogisticsBreakdownModal()">Logistics Cost</span>
                                    </td>
                                    <td class="cost-value cost-value-dynamic" data-total="<?php echo $total_logistics_cost; ?>" data-watt="<?php echo $combined_ppw; ?>" data-module="<?php echo $combined_ppm; ?>" data-pallet="<?php echo $combined_ppp; ?>">$<?php echo number_format($total_logistics_cost, 2); ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total Project Cost</td>
                                    <td class="cost-value cost-value-dynamic" data-total="<?php echo $total_project_cost; ?>" data-watt="<?php echo ($combined_ppw + ($module_cost_per_watt ?? 0)); ?>" data-module="<?php echo $total_raw_modules > 0 ? $total_project_cost / $total_raw_modules : 0; ?>" data-pallet="<?php echo $total_raw_modules > 0 ? ($total_project_cost / $total_raw_modules) * 30 : 0; ?>">$<?php echo number_format($total_project_cost, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Cost Breakdown Chart -->
                    <?php
                    // Calculate percentages for chart (use accrued module cost when available)
                    $chart_module_cost = $has_milestone_data ? $accrued_module_cost : $total_module_cost;
                    $chart_total = $chart_module_cost + $total_freight_cost + $total_warehousing_cost + $total_accessorial_costs + $total_solterra_fee;
                    $module_pct = $chart_total > 0 ? ($chart_module_cost / $chart_total) * 100 : 0;
                    $freight_pct = $chart_total > 0 ? ($total_freight_cost / $chart_total) * 100 : 0;
                    $warehousing_pct = $chart_total > 0 ? ($total_warehousing_cost / $chart_total) * 100 : 0;
                    $other_cost = $total_accessorial_costs + $total_solterra_fee;
                    $other_pct = $chart_total > 0 ? ($other_cost / $chart_total) * 100 : 0;
                    ?>
                    <div class="cost-chart-section">
                        <div class="cashflow-header">
                            <h2>Project Cost Breakdown</h2>
                            <a href="project_cost_details.php?project_id=<?php echo $project_id; ?>" class="view-all-link">View Details</a>
                        </div>
                        <div class="cost-chart-container">
                            <canvas id="costDonutMini" class="cost-chart-canvas-lg" width="220" height="220"></canvas>
                            <div class="cost-chart-legend-lg">
                                <?php if (($has_module_cost_data || $has_milestone_data) && $chart_module_cost > 0): ?>
                                <div class="cost-legend-item-lg">
                                    <div class="cost-legend-dot-lg module"></div>
                                    <div class="cost-legend-info">
                                        <div class="cost-legend-label">Modules<?php echo $has_milestone_data ? ' (Accrued)' : ''; ?></div>
                                        <div class="cost-legend-value">$<?php echo number_format($chart_module_cost, 0); ?></div>
                                    </div>
                                    <div class="cost-legend-pct-lg"><?php echo number_format($module_pct, 1); ?>%</div>
                                </div>
                                <?php endif; ?>
                                <div class="cost-legend-item-lg">
                                    <div class="cost-legend-dot-lg freight"></div>
                                    <div class="cost-legend-info">
                                        <div class="cost-legend-label">Freight</div>
                                        <div class="cost-legend-value">$<?php echo number_format($total_freight_cost, 0); ?></div>
                                    </div>
                                    <div class="cost-legend-pct-lg"><?php echo number_format($freight_pct, 1); ?>%</div>
                                </div>
                                <div class="cost-legend-item-lg">
                                    <div class="cost-legend-dot-lg warehousing"></div>
                                    <div class="cost-legend-info">
                                        <div class="cost-legend-label">Warehousing</div>
                                        <div class="cost-legend-value">$<?php echo number_format($total_warehousing_cost, 0); ?></div>
                                    </div>
                                    <div class="cost-legend-pct-lg"><?php echo number_format($warehousing_pct, 1); ?>%</div>
                                </div>
                                <?php if ($other_cost > 0): ?>
                                <div class="cost-legend-item-lg">
                                    <div class="cost-legend-dot-lg other"></div>
                                    <div class="cost-legend-info">
                                        <div class="cost-legend-label">Other</div>
                                        <div class="cost-legend-value">$<?php echo number_format($other_cost, 0); ?></div>
                                    </div>
                                    <div class="cost-legend-pct-lg"><?php echo number_format($other_pct, 1); ?>%</div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <script>
                    // Cost unit toggle functionality
                    document.querySelectorAll('.cost-unit-chip').forEach(btn => {
                        btn.addEventListener('click', function() {
                            document.querySelectorAll('.cost-unit-chip').forEach(b => b.classList.remove('active'));
                            this.classList.add('active');
                            const unit = this.dataset.unit;

                            // Update Project Cost Summary table
                            document.querySelectorAll('.cost-value-dynamic').forEach(el => {
                                const val = parseFloat(el.dataset[unit]) || 0;
                                if (unit === 'watt') {
                                    el.textContent = '$' + val.toFixed(4) + '/W';
                                } else if (unit === 'module') {
                                    el.textContent = '$' + val.toFixed(2) + '/mod';
                                } else if (unit === 'pallet') {
                                    el.textContent = '$' + val.toFixed(2) + '/plt';
                                } else {
                                    el.textContent = '$' + val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                            });

                            // Update Invoices and Cashflow Forecast table
                            document.querySelectorAll('.financial-value').forEach(el => {
                                const val = parseFloat(el.dataset[unit]) || 0;
                                if (unit === 'watt') {
                                    el.textContent = '$' + val.toFixed(4) + '/W';
                                } else if (unit === 'module') {
                                    el.textContent = '$' + val.toFixed(2) + '/mod';
                                } else if (unit === 'pallet') {
                                    el.textContent = '$' + val.toFixed(2) + '/plt';
                                } else {
                                    el.textContent = '$' + val.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                            });
                        });
                    });

                    // Mini donut chart
                    (function() {
                        const ctx = document.getElementById('costDonutMini');
                        if (ctx) {
                            const chartData = [];
                            const chartColors = [];
                            <?php if (($has_module_cost_data || $has_milestone_data) && $chart_module_cost > 0): ?>
                            chartData.push(<?php echo $chart_module_cost; ?>);
                            chartColors.push('#488C9A');
                            <?php endif; ?>
                            chartData.push(<?php echo $total_freight_cost; ?>);
                            chartColors.push('#3b82f6');
                            chartData.push(<?php echo $total_warehousing_cost; ?>);
                            chartColors.push('#8b5cf6');
                            <?php if ($other_cost > 0): ?>
                            chartData.push(<?php echo $other_cost; ?>);
                            chartColors.push('#9ca3af');
                            <?php endif; ?>

                            new Chart(ctx, {
                                type: 'doughnut',
                                data: {
                                    datasets: [{
                                        data: chartData,
                                        backgroundColor: chartColors,
                                        borderWidth: 0
                                    }]
                                },
                                options: {
                                    cutout: '60%',
                                    responsive: false,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { display: false }, tooltip: { enabled: false } }
                                }
                            });
                        }
                    })();
                    </script>
                </div>
            </div>
    </div>
<?php endif; ?>

<!-- ==================== MODALS ==================== -->
<!-- Note: Logistics Breakdown Modal is in modals.php -->

<!-- Customer Shipping Modal -->
<div id="customerShippingModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="customerShippingModalTitle"></h3>
            <span class="close-modal" onclick="closeCustomerShippingModal()">&times;</span>
        </div>
        <div class="modal-body" id="customerShippingModalContent"></div>
    </div>
</div>

<!-- Conversion Modal -->
<div id="conversionModal" class="warehouse-selection-modal" style="display:none;">
    <div class="modal-content" style="max-width:520px;">
        <div class="modal-header">
            <h3 id="conversionModalTitle">Conversion Needed</h3>
            <span class="close-modal" onclick="closeConversionModal()">&times;</span>
        </div>
        <div class="modal-body" id="conversionModalBody"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:15px;">
            <button class="modal-btn btn-secondary" onclick="closeConversionModal()">Cancel</button>
            <button class="modal-btn btn-primary" onclick="saveConversionModal()">Save</button>
        </div>
    </div>
</div>
