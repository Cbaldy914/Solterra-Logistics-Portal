<?php
/**
 * Projection Header Component
 * Shows projection selector, stepper nav, status, and action buttons
 *
 * Required variables:
 * - $project_id: Project ID
 * - $projections: Array of projections for this project
 * - $current_projection: Currently selected projection (or null)
 * - $can_edit: Boolean for edit permissions
 */

$projection_count = count($projections ?? []);
$current_id = $current_projection['id'] ?? null;
$current_name = $current_projection['projection_name'] ?? 'New Projection';
$current_status = $current_projection['status'] ?? 'draft';
$is_primary = $current_projection['is_primary'] ?? false;
$primary_style = $is_primary ? '' : 'style="display: none;"';
?>

<div class="projection-header">
    <div class="projection-selector-row">
        <div class="projection-selector-group">
            <label class="projection-label">Projection</label>
            <div class="projection-dropdown-wrapper">
                <select id="projectionSelector" class="projection-dropdown" onchange="loadProjection(this.value)">
                    <option value="new" <?php echo !$current_id ? 'selected' : ''; ?>>+ Create New Projection</option>
                    <?php if (!empty($projections)): ?>
                        <optgroup label="Existing Projections">
                        <?php foreach ($projections as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>"
                                    <?php echo ($current_id == $proj['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['projection_name']); ?>
                                <?php echo $proj['is_primary'] ? ' (Primary)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <?php if ($current_id && $can_edit): ?>
                <button type="button" class="btn-edit-name" onclick="editProjectionName()" title="Rename projection">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($current_id): ?>
        <!-- Stepper Nav - integrated into projection header center -->
        <div class="stepper-nav-inline" id="stepperNav">
            <div class="stepper-step active" data-step="modules-costs" onclick="navigateToStep('modules-costs')">
                <span class="stepper-number">1</span>
                <span class="stepper-label">Modules</span>
            </div>
            <div class="stepper-connector"></div>
            <div class="stepper-step" data-step="logistics-plan" onclick="navigateToStep('logistics-plan')">
                <span class="stepper-number">2</span>
                <span class="stepper-label">Logistics & Map</span>
            </div>
            <div class="stepper-connector"></div>
            <div class="stepper-step" data-step="timeline" onclick="navigateToStep('timeline')">
                <span class="stepper-number">3</span>
                <span class="stepper-label">Costs</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can_edit): ?>
        <div class="projection-actions">
            <?php if ($current_id): ?>
                <button type="button" class="btn-projection-save" onclick="saveProjection()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Save
                </button>

                <button type="button" class="btn-projection-duplicate" onclick="duplicateProjection()" title="Duplicate this projection">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                    Duplicate
                </button>

                <?php if (!$is_primary): ?>
                <button type="button" class="btn-projection-primary" onclick="setAsPrimary()" title="Set as primary projection" id="btnSetPrimary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    Set Primary
                </button>
                <?php endif; ?>

                <button type="button" class="btn-projection-delete" onclick="deleteProjection()" title="Delete projection">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($current_projection && !empty($current_projection['notes'])): ?>
    <div class="projection-notes-display">
        <strong>Notes:</strong> <?php echo htmlspecialchars($current_projection['notes']); ?>
    </div>
    <?php endif; ?>
</div>

<style>
.projection-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 16px;
    padding: 16px 24px;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    border: 1px solid var(--gray-200, #e9ecef);
}

.projection-selector-row {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.projection-selector-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

.projection-label {
    font-weight: 600;
    color: #293E4C;
    font-size: 0.9em;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.projection-dropdown-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
}

.projection-dropdown {
    padding: 10px 16px;
    border: 2px solid #e9ecef;
    border-radius: 10px;
    font-size: 1em;
    font-family: 'Poppins', sans-serif;
    font-weight: 500;
    color: #293E4C;
    background: white;
    cursor: pointer;
    min-width: 220px;
    transition: all 0.3s ease;
}

.projection-dropdown:focus {
    outline: none;
    border-color: #488C9A;
    box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
}

.btn-edit-name {
    padding: 8px;
    border: none;
    background: transparent;
    color: #6c757d;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.btn-edit-name:hover {
    background: #e9ecef;
    color: #488C9A;
}

/* Stepper nav inline - centered in projection header */
.stepper-nav-inline {
    display: flex;
    align-items: center;
    gap: 0;
    margin: 0 auto;
    padding: 6px 12px;
    background: rgba(72, 140, 154, 0.04);
    border-radius: 12px;
    border: 1px solid rgba(72, 140, 154, 0.08);
}

.stepper-nav-inline .stepper-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.stepper-nav-inline .stepper-step:hover {
    background: rgba(72, 140, 154, 0.08);
}

.stepper-nav-inline .stepper-step.active {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1), rgba(72, 140, 154, 0.15));
}

.stepper-nav-inline .stepper-step.active .stepper-number {
    background: linear-gradient(135deg, #488C9A, #3A6E7F);
    color: white;
    box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
}

.stepper-nav-inline .stepper-step.active .stepper-label {
    color: #2d5a66;
}

.stepper-nav-inline .stepper-step.completed .stepper-number {
    background: #27ae60;
    color: white;
}

.stepper-nav-inline .stepper-number {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8em;
    background: #dee2e6;
    color: #6c757d;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.stepper-nav-inline .stepper-label {
    font-weight: 600;
    font-size: 0.85em;
    color: #6c757d;
    transition: color 0.2s ease;
}

.stepper-nav-inline .stepper-connector {
    width: 24px;
    height: 2px;
    background: #dee2e6;
    flex-shrink: 0;
}

.stepper-nav-inline .stepper-connector.completed {
    background: #27ae60;
}

/* Projection status badges in page subtitle */
.header-projection-name {
    font-weight: 500;
}

.header-status-pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 0.75em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    vertical-align: middle;
    margin-left: 6px;
}

.header-status-pill.completed {
    background: #d4edda;
    color: #155724;
}

.header-status-pill.primary {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
}

/* Improved Save and Set Primary buttons */
.btn-projection-save {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    font-size: 0.88em;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    border: 2px solid #488C9A;
    border-radius: 10px;
    background: white;
    color: #488C9A;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-projection-save:hover {
    background: #488C9A;
    color: white;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25);
    transform: translateY(-1px);
}

.btn-projection-save svg {
    flex-shrink: 0;
}

.btn-projection-duplicate {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 0.88em;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    border: 2px solid #d0dde2;
    border-radius: 10px;
    background: white;
    color: #3d5561;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-projection-duplicate:hover {
    border-color: #488C9A;
    color: #2d4a55;
    background: #f6fbfc;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.18);
    transform: translateY(-1px);
}

.btn-projection-duplicate svg {
    flex-shrink: 0;
}

.btn-projection-primary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    font-size: 0.88em;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #f0c850 0%, #e6b422 100%);
    color: #5a4500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-projection-primary:hover {
    box-shadow: 0 4px 14px rgba(230, 180, 34, 0.4);
    transform: translateY(-1px);
    filter: brightness(1.05);
}

.btn-projection-primary svg {
    flex-shrink: 0;
    color: #7a6000;
}

.btn-projection-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: white;
    color: #adb5bd;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-projection-delete:hover {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
}

.projection-status-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-draft {
    background: #fff3cd;
    color: #856404;
}

.status-active {
    background: #d4edda;
    color: #155724;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.status-archived {
    background: #e9ecef;
    color: #6c757d;
}

.primary-badge {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.projection-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
}

.btn-sm {
    padding: 8px 14px;
    font-size: 0.9em;
}

.btn-sm svg {
    vertical-align: middle;
    margin-right: 4px;
}

.btn-danger {
    background: #f8d7da;
    color: #721c24;
    border: none;
}

.btn-danger:hover {
    background: #f5c6cb;
}

.projection-notes-display {
    margin-top: 16px;
    padding: 12px 16px;
    background: rgba(72, 140, 154, 0.05);
    border-radius: 10px;
    font-size: 0.95em;
    color: #495057;
    border-left: 3px solid #488C9A;
}

@media (max-width: 1100px) {
    .stepper-nav-inline {
        order: 10;
        margin: 8px 0 0 0;
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .projection-selector-row {
        flex-direction: column;
        align-items: stretch;
    }

    .projection-actions {
        margin-left: 0;
        flex-wrap: wrap;
    }

    .projection-dropdown {
        width: 100%;
    }

    .stepper-nav-inline {
        margin: 8px 0 0 0;
        justify-content: center;
    }

    .stepper-nav-inline .stepper-step {
        padding: 6px 10px;
    }

    .stepper-nav-inline .stepper-label {
        font-size: 0.78em;
    }

    .stepper-nav-inline .stepper-connector {
        width: 16px;
    }
}
</style>
