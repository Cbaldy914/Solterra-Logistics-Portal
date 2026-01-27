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

<!-- Main Tabs -->
<div class="main-tabs-container">
    <div class="main-tabs">
        <button class="main-tab-btn active" data-tab="project-overview" onclick="switchMainTab('project-overview')">
            Project Overview
        </button>
        <button class="main-tab-btn" data-tab="analytics" onclick="switchMainTab('analytics')">
            Analytics
        </button>
    </div>
</div>

<!-- Project Overview Tab Content -->
<div id="project-overview-tab" class="main-tab-content active">
    <!-- Sub-tabs -->
    <div class="sub-tabs-container">
        <div class="sub-tabs">
            <button class="sub-tab-btn active" data-subtab="timeline" onclick="switchSubTab('project-overview', 'timeline')">
                Timeline
            </button>
            <button class="sub-tab-btn" data-subtab="site" onclick="switchSubTab('project-overview', 'site')">
                Site
            </button>
            <button class="sub-tab-btn" data-subtab="modules" onclick="switchSubTab('project-overview', 'modules')">
                Modules
            </button>
        </div>
    </div>

    <!-- Timeline Sub-tab -->
    <div id="subtab-timeline" class="sub-tab-content active">
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

        <div class="timeline-container">
            <ul class="timeline" style="--progress-width: <?php echo $progress_percentage; ?>%">

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
                            ["key" => "Cleared Customs", "class" => "cleared-customs", "label" => "Cleared Customs"],
                            ["key" => "In Transit to Warehouse", "class" => "in-transit-warehouse", "label" => "In Transit to Warehouse"],
                            ["key" => "In Warehouse", "class" => "in-warehouse", "label" => "In Warehouse"],
                            ["key" => "In Transit to Project", "class" => "in-transit-project", "label" => "In Transit to Project"],
                            ["key" => "Delivered", "class" => "delivered", "label" => "Delivered"],
                        ];

                        foreach ($shipping_statuses_list as $status_info):
                            $status_key = $status_info["key"];
                            $pallets = $status_totals[$status_key]["pallets"] ?? 0;
                            $modules = $status_totals[$status_key]["modules"] ?? 0;

                            if ($pallets > 0):
                                $truckloads = null;
                                if (!empty($average_pallets_per_truck)) {
                                    $truckloads = round($pallets / $average_pallets_per_truck, 1);
                                } elseif (!empty($average_modules_per_truck)) {
                                    $truckloads = round($modules / $average_modules_per_truck, 1);
                                }
                                $mws = 0;
                                if (!empty($wattages) && $modules > 0) {
                                    $total_watts = array_sum($wattages);
                                    $avg_wattage = count($wattages) > 0 ? ($total_watts / count($wattages)) : 0;
                                    $mws = round(($modules * $avg_wattage) / 1000000, 2);
                                }
                                // Use unified class but with role-based click handler
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

    <!-- Site Sub-tab -->
    <div id="subtab-site" class="sub-tab-content" style="display:none;">
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

    <!-- Modules Sub-tab -->
    <div id="subtab-modules" class="sub-tab-content" style="display:none;">
        <div class="info-container">
            <div class="header-with-button">
                <h2>Module Information</h2>
                <?php if ($isGlobalAdmin): ?>
                <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="info-action-button" style="margin:0; padding:10px 16px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-plus"></i> Add Module Batch
                </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($module_batches)): ?>
                <?php foreach ($module_batches as $batch): ?>
                    <div class="module-batch-section" style="position:relative;">
                        <?php if ($isGlobalAdmin): ?>
                        <a href="edit_module_batch.php?batch_id=<?php echo $batch['id']; ?>" class="batch-edit-btn" title="Edit <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Batch'); ?>" style="position:absolute; top:16px; right:16px; background:#488C9A; color:#fff; padding:6px 12px; border-radius:6px; font-size:0.85em; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <?php endif; ?>
                        <h3 class="batch-title">
                            <?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch'); ?>
                            <?php if (!empty($batch['is_replacement_batch'])): ?>
                                <span class="batch-badge">Replacement</span>
                            <?php endif; ?>
                        </h3>

                        <div class="info-grid">
                            <div class="info-section">
                                <h3>Basic Information</h3>
                                <div class="info-item">
                                    <label>Price per Watt:</label>
                                    <span>
                                        <?php if (!empty($batch['cost_per_watt']) && (float)$batch['cost_per_watt'] > 0): ?>
                                            $<?php echo number_format((float)$batch['cost_per_watt'], 4); ?> / W
                                        <?php else: ?>
                                            Not specified
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if (!empty($batch['wattages'])): ?>
                                    <div class="info-item">
                                        <label>Wattages:</label>
                                        <span>
                                            <?php
                                            $wattage_labels = array_map(function($w) {
                                                return $w['wattage'] . 'W (' . number_format($w['quantity']) . ')';
                                            }, $batch['wattages']);
                                            echo implode(', ', $wattage_labels);
                                            ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php
                                // Calculate total modules for this batch
                                $batch_total_modules = 0;
                                if (!empty($batch['wattages'])) {
                                    foreach ($batch['wattages'] as $w) {
                                        $batch_total_modules += $w['quantity'];
                                    }
                                }
                                ?>
                                <div class="info-item">
                                    <label>Total Modules:</label>
                                    <span><?php echo number_format($batch_total_modules); ?></span>
                                </div>
                            </div>

                            <div class="info-section">
                                <h3>Pallet Specifications</h3>
                                <div class="info-item">
                                    <label>Modules per Pallet:</label>
                                    <span><?php echo $batch['modules_per_pallet'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Pallets per Truck:</label>
                                    <span><?php echo $batch['pallets_per_truck'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Modules per Truck:</label>
                                    <span><?php echo $batch['modules_per_truck'] ?? 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Module Documents Section - Inside Batch Card, own grid row -->
                        <div class="info-grid">
                            <div class="info-section" style="grid-column: 1 / -1;">
                                <h3>
                                    Documents
                                    <a href="project_documents.php?project_id=<?php echo $project_id; ?>&filter_type=modules&module_id=<?php echo $batch['id']; ?>" id="module-docs-count-link-<?php echo $batch['id']; ?>" class="docs-count-link" style="display: none;"></a>
                                </h3>
                                <div id="module-docs-list-<?php echo $batch['id']; ?>" class="docs-grid">
                                    <div class="docs-loading"><i class="fas fa-spinner fa-spin"></i> Loading documents...</div>
                                </div>
                            </div>
                        </div>

                        <div class="batch-actions">
                            <a href="module_overview.php?batch_id=<?php echo $batch['id']; ?>" class="info-action-button">
                                View Pallets & Module Status
                            </a>
                        </div>

                        <script>
                        (function() {
                            const projectId = <?php echo $project_id; ?>;
                            const batchId = <?php echo $batch['id']; ?>;
                            // Fetch all module documents, then filter to show those matching this batch OR unassigned (no module_id)
                            fetch(`get_project_documents.php?project_id=${projectId}&document_type_in=modules`)
                                .then(r => r.json())
                                .then(data => {
                                    const container = document.getElementById('module-docs-list-' + batchId);
                                    const countLink = document.getElementById('module-docs-count-link-' + batchId);

                                    if (!data.success || !data.documents || data.documents.length === 0) {
                                        container.innerHTML = '<div class="docs-empty"><i class="fas fa-folder-open"></i><span>No documents uploaded yet</span></div>';
                                        return;
                                    }

                                    // Filter: show documents that match this batch's module_id OR have no module_id (legacy/unassigned)
                                    const filteredDocs = data.documents.filter(doc =>
                                        doc.module_id == batchId || !doc.module_id
                                    );

                                    if (filteredDocs.length === 0) {
                                        container.innerHTML = '<div class="docs-empty"><i class="fas fa-folder-open"></i><span>No documents uploaded yet</span></div>';
                                        return;
                                    }

                                    countLink.textContent = `(${filteredDocs.length})`;
                                    countLink.style.display = 'inline';

                                    let html = '';
                                    filteredDocs.forEach(doc => {
                                        const ext = (doc.original_name || '').split('.').pop().toLowerCase();
                                        let iconClass = 'fas fa-file';
                                        let iconColor = '#6c757d';
                                        if (['pdf'].includes(ext)) { iconClass = 'fas fa-file-pdf'; iconColor = '#dc3545'; }
                                        else if (['doc', 'docx'].includes(ext)) { iconClass = 'fas fa-file-word'; iconColor = '#2b579a'; }
                                        else if (['xls', 'xlsx'].includes(ext)) { iconClass = 'fas fa-file-excel'; iconColor = '#217346'; }
                                        else if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) { iconClass = 'fas fa-file-image'; iconColor = '#488C9A'; }

                                        let subtype = doc.document_sub_type || 'Module Document';

                                        html += `
                                            <div class="doc-item" onclick="openDocPreview('${doc.file_path}', '${(doc.original_name || 'Document').replace(/'/g, "\\'")}', '${ext}')" style="cursor: pointer;">
                                                <i class="${iconClass}" style="color: ${iconColor};"></i>
                                                <div class="doc-details">
                                                    <span class="doc-name" title="${doc.original_name || 'Document'}">${doc.original_name || 'Document'}</span>
                                                    <span class="doc-subtype">${subtype}</span>
                                                </div>
                                            </div>
                                        `;
                                    });
                                    container.innerHTML = html;
                                })
                                .catch(err => {
                                    document.getElementById('module-docs-list-' + batchId).innerHTML = '<div class="docs-error"><i class="fas fa-exclamation-triangle"></i> Error loading documents</div>';
                                });
                        })();
                        </script>
                    </div>
                <?php endforeach; ?>
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
</div>

<!-- Analytics Tab Content -->
<div id="analytics-tab" class="main-tab-content" style="display:none;">
    <!-- Sub-tabs -->
    <div class="sub-tabs-container">
        <div class="sub-tabs">
            <button class="sub-tab-btn active" data-subtab="deliveries" onclick="switchSubTab('analytics', 'deliveries')">
                Deliveries
            </button>
            <button class="sub-tab-btn" data-subtab="financial" onclick="switchSubTab('analytics', 'financial')">
                Financial
            </button>
        </div>
    </div>

    <!-- Deliveries Sub-tab -->
    <div id="subtab-deliveries" class="sub-tab-content active">
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

        <div class="tables-and-charts">
                <div class="left-side">
                    <h2>Next 5 Weeks of Deliveries</h2>
                    <div class="table-responsive">
                        <table id="table1">
                            <thead>
                                <tr>
                                    <th data-full="Module Type"><span class="th-short">Type</span></th>
                                    <th data-full="Total Order"><span class="th-short">Order</span></th>
                                    <th data-full="Delivered"><span class="th-short">Deliv.</span></th>
                                    <?php foreach($weeks as $wk): ?>
                                        <th><?php echo $wk['end']->format('n/j'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('delivery-row')">
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php foreach($anticipated_quantities_combined as $qq): ?>
                                        <td><?php echo number_format($qq,($view_mode=='mw')?2:0);?></td>
                                    <?php endforeach;?>
                                </tr>
                                <?php foreach($sub_rows as $lbl=>$sr): ?>
                                    <tr class="delivery-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($sr['wattage_label']);?></td>
                                        <td><?php echo number_format($sr['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format($sr['delivered'],($view_mode=='mw')?2:0);?></td>
                                        <?php foreach($sr['anticipated_quantities'] as $val): ?>
                                            <td><?php echo number_format($val,($view_mode=='mw')?2:0);?></td>
                                        <?php endforeach;?>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Anticipated vs Actual Deliveries</h2>
                    <canvas id="lineChart"></canvas>
                </div>

                <div class="right-side">
                    <h2>Module Delivery Status</h2>
                    <div class="table-responsive">
                        <table id="table2">
                            <thead>
                                <tr>
                                    <th data-full="Module Type"><span class="th-short">Type</span></th>
                                    <th data-full="Total Order"><span class="th-short">Order</span></th>
                                    <th data-full="At Manufacturer"><span class="th-short">At Mfr.</span></th>
                                    <?php if ($on_water_combined > 0): ?>
                                    <th data-full="On Water"><span class="th-short">Water</span></th>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <th data-full="Cleared Customs"><span class="th-short">Customs</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                    <th data-full="In Transit to Warehouse" title="In Transit to Warehouse"><span class="th-short">Transit-Whse</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_warehouse_combined > 0): ?>
                                    <th data-full="In Warehouse"><span class="th-short">Whse</span></th>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_project_combined > 0): ?>
                                    <th data-full="In Transit to Project" title="In Transit to Project"><span class="th-short">Transit-Proj</span></th>
                                    <?php endif; ?>
                                    <th data-full="Delivered to Project"><span class="th-short">Delivered</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr onclick="toggleSubRows('status-row')">
                                    <td><?php echo htmlspecialchars($module_type_combined);?></td>
                                    <td><?php echo number_format($total_order_combined,($view_mode=='mw')?2:0);?></td>
                                    <td><?php echo number_format($at_manufacturer_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php if ($on_water_combined > 0): ?>
                                    <td><?php echo number_format($on_water_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($cleared_customs_combined > 0): ?>
                                    <td><?php echo number_format($cleared_customs_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                    <td><?php echo number_format($in_transit_to_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_warehouse_combined > 0): ?>
                                    <td><?php echo number_format($in_warehouse_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <?php if ($in_transit_to_project_combined > 0): ?>
                                    <td><?php echo number_format($in_transit_to_project_combined,($view_mode=='mw')?2:0);?></td>
                                    <?php endif; ?>
                                    <td><?php echo number_format($delivered_combined,($view_mode=='mw')?2:0);?></td>
                                </tr>
                                <?php foreach($sub_rows_status as $lbl=>$srs): ?>
                                    <tr class="status-row" style="display:none;">
                                        <td><?php echo htmlspecialchars($srs['wattage_label']);?></td>
                                        <td><?php echo number_format($srs['total_order'],($view_mode=='mw')?2:0);?></td>
                                        <td><?php echo number_format(($srs['at_manufacturer'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php if ($on_water_combined > 0): ?>
                                        <td><?php echo number_format(($srs['on_water'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($cleared_customs_combined > 0): ?>
                                        <td><?php echo number_format(($srs['cleared_customs'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_transit_to_warehouse_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_transit_to_warehouse'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_warehouse_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_warehouse'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <?php if ($in_transit_to_project_combined > 0): ?>
                                        <td><?php echo number_format(($srs['in_transit_to_project'] ?? 0),($view_mode=='mw')?2:0);?></td>
                                        <?php endif; ?>
                                        <td><?php echo number_format($srs['delivered'],($view_mode=='mw')?2:0);?></td>
                                    </tr>
                                <?php endforeach;?>
                            </tbody>
                        </table>
                    </div>
                    <h2>Delivery Overview</h2>
                    <div class="chart-container">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
            </div>
    </div>

    <!-- Financial Sub-tab -->
    <div id="subtab-financial" class="sub-tab-content" style="display:none;">
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
                    <h2>Forecasted vs Actual Cost</h2>
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

                    <h2>Project Cost Summary</h2>
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
                        <h2>Project Cost Breakdown</h2>
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
</div>

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
