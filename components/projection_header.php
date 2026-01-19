<?php
/**
 * Projection Header Component
 * Shows projection selector, status, and action buttons
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
        <div class="projection-status-group">
            <span class="status-badge status-<?php echo $current_status; ?>">
                <?php echo ucfirst($current_status); ?>
            </span>
            <?php if ($is_primary): ?>
            <span class="primary-badge">Primary</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($can_edit): ?>
        <div class="projection-actions">
            <?php if ($current_id): ?>
                <button type="button" class="btn btn-sm btn-secondary" onclick="saveProjection()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Save
                </button>

                <?php if (!$is_primary): ?>
                <button type="button" class="btn btn-sm btn-secondary" onclick="setAsPrimary()" title="Set as primary projection">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    Set Primary
                </button>
                <?php endif; ?>

                <button type="button" class="btn btn-sm btn-secondary" onclick="saveAsTemplate()" title="Save as reusable template">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="3" y1="9" x2="21" y2="9"/>
                        <line x1="9" y1="21" x2="9" y2="9"/>
                    </svg>
                    Save as Template
                </button>

                <button type="button" class="btn btn-sm btn-danger" onclick="deleteProjection()" title="Delete projection">
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
    padding: 20px 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(72, 140, 154, 0.1);
}

.projection-selector-row {
    display: flex;
    align-items: center;
    gap: 20px;
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
}
</style>
