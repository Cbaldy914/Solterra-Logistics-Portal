<?php
/**
 * Projection Logistics Plan Component
 * Renders a delivery plan container with manufacturer/project context.
 *
 * Required variables:
 * - $stops: Array of projection stops (ordered)
 * - $legs: Array of projection legs
 * - $allocated_modules: Array of module allocations
 * - $can_edit: Boolean for edit permissions
 */

$manufacturer_names = [];
if (!empty($allocated_modules)) {
    foreach ($allocated_modules as $alloc) {
        if (!empty($alloc['manufacturer_name'])) {
            $manufacturer_names[] = $alloc['manufacturer_name'];
        }
    }
}

$manufacturer_names = array_values(array_unique($manufacturer_names));
$manufacturer_label = !empty($manufacturer_names)
    ? implode(', ', $manufacturer_names)
    : 'Set by module allocation';

$project_label = $project['project_name'] ?? 'Project Site';
?>

<div class="logistics-plan card">
    <div class="card-header">
        <div class="card-title">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12h18"/>
                <path d="M12 3v18"/>
                <circle cx="12" cy="12" r="9"/>
            </svg>
            Logistics Plan
        </div>
        <div class="plan-meta">
            <span class="plan-chip">Manufacturer: <?php echo htmlspecialchars($manufacturer_label); ?></span>
            <span class="plan-chip">Project: <?php echo htmlspecialchars($project_label); ?></span>
        </div>
    </div>
    <div class="card-body">
        <p class="plan-intro">Build deliveries step-by-step. Choose destination type, cadence, and fees. Milestones auto-apply as each delivery reaches its destination.</p>
        <div id="deliveryPlan" class="delivery-plan"></div>
        <div class="delivery-plan-empty" id="deliveryPlanEmpty">
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M7 12h10"/>
                </svg>
                <p>Add your first delivery to start planning the route.</p>
            </div>
        </div>
    </div>
</div>
