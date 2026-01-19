<?php
/**
 * Projection Journey Planner Component
 * Shows origin -> stops -> destination with legs between them
 *
 * Required variables:
 * - $stops: Array of projection stops (ordered)
 * - $legs: Array of projection legs
 * - $can_edit: Boolean for edit permissions
 * - $total_pallets: Total pallets in projection (for cost calculations)
 */

// Separate stops by type
$origin = null;
$destination = null;
$intermediate_stops = [];

foreach ($stops as $stop) {
    if ($stop['stop_type'] === 'origin') {
        $origin = $stop;
    } elseif ($stop['stop_type'] === 'destination') {
        $destination = $stop;
    } else {
        $intermediate_stops[] = $stop;
    }
}

// Create a map of legs by from_stop_id and to_stop_id
$legs_map = [];
$legs_by_to = [];
foreach ($legs as $leg) {
    $legs_map[$leg['from_stop_id']] = $leg;
    $legs_by_to[$leg['to_stop_id']] = $leg;
}

// Find direct leg from origin to destination (if no intermediate stops)
$direct_leg = null;
if ($origin && $destination && empty($intermediate_stops)) {
    foreach ($legs as $leg) {
        if ($leg['from_stop_id'] == $origin['id'] && $leg['to_stop_id'] == $destination['id']) {
            $direct_leg = $leg;
            break;
        }
    }
}
?>

<div class="journey-planner">
    <div class="planner-header">
        <div class="planner-title">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="2" y1="12" x2="22" y2="12"/>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
            </svg>
            Journey Planner
        </div>
        <?php if ($can_edit): ?>
        <div class="planner-help">
            <div class="help-tooltip">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span>How to use</span>
                <div class="help-dropdown">
                    <div class="help-item">
                        <div class="help-icon" style="background: #28a745;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                        </div>
                        <div>
                            <strong>Origin</strong> - Set automatically from module manufacturer
                        </div>
                    </div>
                    <div class="help-item">
                        <div class="help-icon" style="background: #488C9A;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/></svg>
                        </div>
                        <div>
                            <strong>Click shipping legs</strong> - Configure transport mode, dates, costs & delivery rates
                        </div>
                    </div>
                    <div class="help-item">
                        <div class="help-icon" style="background: #6c757d;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/></svg>
                        </div>
                        <div>
                            <strong>Add warehouse stops</strong> - Insert ports, storage facilities, or customs points
                        </div>
                    </div>
                    <div class="help-item">
                        <div class="help-icon" style="background: #dc3545;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/></svg>
                        </div>
                        <div>
                            <strong>Destination</strong> - Set automatically from project site
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="journey-flow">
        <!-- ORIGIN -->
        <div class="journey-stop origin-stop">
            <div class="stop-marker">
                <div class="marker-icon marker-origin">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <span class="marker-label">ORIGIN</span>
            </div>
            <div class="stop-content">
                <?php if ($origin): ?>
                    <div class="stop-name"><?php echo htmlspecialchars($origin['location_name']); ?></div>
                    <?php if (!empty($origin['location_address'])): ?>
                        <div class="stop-address"><?php echo htmlspecialchars($origin['location_address']); ?></div>
                    <?php endif; ?>
                    <div class="stop-milestone">
                        <span class="milestone-trigger">PO Execution milestone triggers at order creation</span>
                    </div>
                <?php else: ?>
                    <div class="stop-empty">
                        <span>Origin will be set from module manufacturer</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // DEBUG: see what's happening
        echo "<!-- DEBUG: intermediate_stops=" . count($intermediate_stops) . ", origin=" . ($origin ? 'yes' : 'no') . ", destination=" . ($destination ? 'yes' : 'no') . " -->";

        // If NO intermediate stops, show direct delivery leg from origin to destination
        // Show even if origin is pending (null) - user needs to see the shipping config area
        if (empty($intermediate_stops) && $destination):
        ?>
        <!-- DIRECT DELIVERY LEG (no intermediate stops) -->
        <?php
        $origin_id = $origin ? $origin['id'] : 'null';
        $dest_id = $destination ? $destination['id'] : 'null';
        ?>
        <div class="journey-leg direct-leg" data-leg-id="<?php echo $direct_leg['id'] ?? 'new'; ?>" data-from-stop="<?php echo $origin_id; ?>" data-to-stop="<?php echo $dest_id; ?>">
            <div class="leg-connector">
                <div class="leg-line leg-line-long"></div>
                <div class="leg-arrow">
                    <?php
                    $transport = $direct_leg['transport_mode'] ?? 'truck';
                    $transport_icons = [
                        'ocean' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 16l2.5-2.5L7 16l2.5-2.5L12 16l2.5-2.5L17 16l2.5-2.5L22 16"/><path d="M4 20h16"/><path d="M5 12v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/></svg>',
                        'truck' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                        'rail' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/><circle cx="8" cy="15" r="1"/><circle cx="16" cy="15" r="1"/><path d="M8 19l-2 3"/><path d="M16 19l2 3"/></svg>',
                        'air' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>'
                    ];
                    echo $transport_icons[$transport] ?? $transport_icons['truck'];
                    ?>
                </div>
            </div>
            <div class="leg-content delivery-leg <?php echo empty($direct_leg) ? 'leg-unconfigured' : ''; ?>" <?php echo $can_edit ? 'onclick="editLeg(' . ($direct_leg['id'] ?? 'null') . ', ' . $origin_id . ', ' . $dest_id . ')"' : ''; ?>>
                <?php if (empty($direct_leg)): ?>
                    <!-- Unconfigured state - prompt user to configure -->
                    <div class="leg-unconfigured-prompt">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                        <div class="prompt-text">
                            <strong>Configure Shipping</strong>
                            <?php if (!$origin): ?>
                                <span>Origin will be set when you save. Click to configure transport, dates & costs.</span>
                            <?php else: ?>
                                <span>Click to set transport mode, dates, delivery rate & costs</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($can_edit): ?>
                        <button type="button" class="btn-configure-leg">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Configure
                        </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Configured state - show details -->
                    <div class="leg-header">
                        <span class="leg-type">
                            <?php echo ucfirst($transport); ?> Delivery
                        </span>
                        <span class="leg-milestone-badge delivery-milestone">
                            Triggers: Project Delivery
                        </span>
                    </div>
                    <div class="leg-details">
                        <?php if (!empty($direct_leg['start_date'])): ?>
                            <div class="leg-dates">
                                <?php echo date('M j', strtotime($direct_leg['start_date'])); ?>
                                <?php if (!empty($direct_leg['end_date'])): ?>
                                    - <?php echo date('M j, Y', strtotime($direct_leg['end_date'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($direct_leg['delivery_rate'])): ?>
                            <div class="leg-stat">
                                <?php echo $direct_leg['delivery_rate']; ?> trucks/<?php echo str_replace('per_', '', $direct_leg['delivery_rate_unit'] ?? 'week'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($direct_leg['total_freight_cost'])): ?>
                        <div class="leg-cost">
                            <strong>$<?php echo number_format($direct_leg['total_freight_cost'], 2); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                        <div class="leg-edit-hint">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Click to edit shipping details
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        endif; // End direct delivery leg

        // Display legs and intermediate stops in order
        $previous_stop_id = $origin ? $origin['id'] : null;
        $stop_index = 0;

        // Build ordered journey from stops
        foreach ($intermediate_stops as $stop):
            // Find the leg TO this stop
            $leg_to_stop = null;
            foreach ($legs as $leg) {
                if ($leg['to_stop_id'] == $stop['id']) {
                    $leg_to_stop = $leg;
                    break;
                }
            }
        ?>

        <!-- LEG before stop -->
        <?php if ($leg_to_stop || $stop_index == 0): ?>
        <div class="journey-leg" data-leg-id="<?php echo $leg_to_stop['id'] ?? 'new'; ?>" data-from-stop="<?php echo $previous_stop_id; ?>" data-to-stop="<?php echo $stop['id']; ?>">
            <div class="leg-connector">
                <div class="leg-line"></div>
                <div class="leg-arrow">
                    <?php
                    $transport = $leg_to_stop['transport_mode'] ?? 'truck';
                    $transport_icons = [
                        'ocean' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 16l2.5-2.5L7 16l2.5-2.5L12 16l2.5-2.5L17 16l2.5-2.5L22 16"/><path d="M4 20h16"/><path d="M5 12v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/></svg>',
                        'truck' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                        'rail' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/><circle cx="8" cy="15" r="1"/><circle cx="16" cy="15" r="1"/><path d="M8 19l-2 3"/><path d="M16 19l2 3"/></svg>',
                        'air' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>'
                    ];
                    echo $transport_icons[$transport] ?? $transport_icons['truck'];
                    ?>
                </div>
            </div>
            <div class="leg-content <?php echo empty($leg_to_stop) ? 'leg-unconfigured' : ''; ?>" <?php echo $can_edit ? 'onclick="editLeg(' . ($leg_to_stop['id'] ?? 'null') . ', ' . $previous_stop_id . ', ' . $stop['id'] . ')"' : ''; ?>>
                <?php if (empty($leg_to_stop)): ?>
                    <!-- Unconfigured state -->
                    <div class="leg-unconfigured-prompt">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                        <div class="prompt-text">
                            <strong>Configure Shipping Leg</strong>
                            <span>Click to set transport, dates & costs</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="leg-header">
                        <span class="leg-type">
                            <?php echo ucfirst($transport); ?> Leg
                        </span>
                        <?php if (!empty($leg_to_stop['triggers_milestone'])): ?>
                            <span class="leg-milestone-badge">
                                Triggers: <?php echo ucwords(str_replace('_', ' ', $leg_to_stop['triggers_milestone'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="leg-details">
                        <?php if (!empty($leg_to_stop['start_date'])): ?>
                            <div class="leg-dates">
                                <?php echo date('M j', strtotime($leg_to_stop['start_date'])); ?>
                                <?php if (!empty($leg_to_stop['end_date'])): ?>
                                    - <?php echo date('M j, Y', strtotime($leg_to_stop['end_date'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($leg_to_stop['trucks_required'])): ?>
                            <div class="leg-stat"><?php echo $leg_to_stop['trucks_required']; ?> trucks</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($leg_to_stop['total_freight_cost'])): ?>
                        <div class="leg-cost">
                            <strong>$<?php echo number_format($leg_to_stop['total_freight_cost'], 2); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                        <div class="leg-edit-hint">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Click to edit
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- INTERMEDIATE STOP -->
        <div class="journey-stop intermediate-stop" data-stop-id="<?php echo $stop['id']; ?>">
            <div class="stop-marker">
                <div class="marker-icon marker-warehouse">
                    <?php if ($stop['stop_type'] === 'port'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 17v1a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-1"/>
                            <path d="M12 2v11"/>
                            <path d="M7 7l5 5 5-5"/>
                        </svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="7" width="20" height="14" rx="2"/>
                            <path d="M12 7V4"/>
                            <path d="M12 4H8"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <span class="marker-label"><?php echo strtoupper($stop['stop_type']); ?></span>
            </div>
            <div class="stop-content">
                <div class="stop-name-row">
                    <span class="stop-name"><?php echo htmlspecialchars($stop['location_name']); ?></span>
                    <?php if ($stop['is_customs_clearance']): ?>
                        <span class="customs-badge">Customs Clearance</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($stop['location_address'])): ?>
                    <div class="stop-address"><?php echo htmlspecialchars($stop['location_address']); ?></div>
                <?php endif; ?>

                <!-- Dates -->
                <?php if (!empty($stop['estimated_arrival_date']) || !empty($stop['estimated_departure_date'])): ?>
                    <div class="stop-dates">
                        <?php if (!empty($stop['estimated_arrival_date'])): ?>
                            <span>Arrive: <?php echo date('M j, Y', strtotime($stop['estimated_arrival_date'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($stop['estimated_departure_date'])): ?>
                            <span>Depart: <?php echo date('M j, Y', strtotime($stop['estimated_departure_date'])); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Fees Summary -->
                <?php if (!empty($stop['fees'])): ?>
                    <div class="stop-fees">
                        <div class="fees-header">
                            <span class="fees-title">Fees at this stop</span>
                            <span class="fees-total">$<?php echo number_format($stop['total_fees'] ?? 0, 2); ?></span>
                        </div>
                        <div class="fees-list">
                            <?php foreach ($stop['fees'] as $fee): ?>
                                <div class="fee-item">
                                    <span class="fee-name"><?php echo htmlspecialchars($fee['fee_name']); ?></span>
                                    <span class="fee-amount">$<?php echo number_format($fee['estimated_cost'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($stop['is_customs_clearance']): ?>
                    <div class="stop-milestone">
                        <span class="milestone-trigger">Customs Cleared milestone triggers here</span>
                    </div>
                <?php endif; ?>

                <?php if ($can_edit): ?>
                    <div class="stop-actions">
                        <button type="button" class="btn-edit-stop" onclick="editStop(<?php echo $stop['id']; ?>)">
                            Edit
                        </button>
                        <button type="button" class="btn-delete-stop" onclick="deleteStop(<?php echo $stop['id']; ?>)">
                            Remove
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
            $previous_stop_id = $stop['id']; // Update for next iteration
            $stop_index++;
        endforeach;

        // Get the last stop before destination (last intermediate stop or origin)
        $last_stop_before_dest = !empty($intermediate_stops) ? end($intermediate_stops)['id'] : ($origin ? $origin['id'] : null);

        // Final leg to destination
        $leg_to_dest = null;
        if ($destination) {
            foreach ($legs as $leg) {
                if ($leg['to_stop_id'] == $destination['id']) {
                    $leg_to_dest = $leg;
                    break;
                }
            }
        }
        ?>

        <!-- FINAL LEG to destination (only when there are intermediate stops) -->
        <?php if (($leg_to_dest || count($intermediate_stops) > 0) && !empty($intermediate_stops)): ?>
        <div class="journey-leg" data-leg-id="<?php echo $leg_to_dest['id'] ?? 'new'; ?>" data-from-stop="<?php echo $last_stop_before_dest; ?>" data-to-stop="<?php echo $destination['id']; ?>">
            <div class="leg-connector">
                <div class="leg-line"></div>
                <div class="leg-arrow">
                    <?php
                    $transport = $leg_to_dest['transport_mode'] ?? 'truck';
                    $transport_icons = [
                        'ocean' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 16l2.5-2.5L7 16l2.5-2.5L12 16l2.5-2.5L17 16l2.5-2.5L22 16"/><path d="M4 20h16"/><path d="M5 12v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/></svg>',
                        'truck' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                        'rail' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16"/><path d="M12 3v8"/><circle cx="8" cy="15" r="1"/><circle cx="16" cy="15" r="1"/><path d="M8 19l-2 3"/><path d="M16 19l2 3"/></svg>',
                        'air' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>'
                    ];
                    echo $transport_icons[$transport] ?? $transport_icons['truck'];
                    ?>
                </div>
            </div>
            <div class="leg-content delivery-leg <?php echo empty($leg_to_dest) ? 'leg-unconfigured' : ''; ?>" <?php echo $can_edit ? 'onclick="editLeg(' . ($leg_to_dest['id'] ?? 'null') . ', ' . $last_stop_before_dest . ', ' . $destination['id'] . ')"' : ''; ?>>
                <?php if (empty($leg_to_dest)): ?>
                    <!-- Unconfigured state -->
                    <div class="leg-unconfigured-prompt">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                        <div class="prompt-text">
                            <strong>Configure Final Delivery</strong>
                            <span>Click to set transport, dates & costs</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="leg-header">
                        <span class="leg-type">
                            <?php echo ucfirst($transport); ?> Delivery
                        </span>
                        <span class="leg-milestone-badge delivery-milestone">
                            Triggers: Project Delivery
                        </span>
                    </div>
                    <div class="leg-details">
                        <?php if (!empty($leg_to_dest['start_date'])): ?>
                            <div class="leg-dates">
                                <?php echo date('M j', strtotime($leg_to_dest['start_date'])); ?>
                                <?php if (!empty($leg_to_dest['end_date'])): ?>
                                    - <?php echo date('M j, Y', strtotime($leg_to_dest['end_date'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($leg_to_dest['delivery_rate'])): ?>
                            <div class="leg-stat">
                                <?php echo $leg_to_dest['delivery_rate']; ?> trucks/<?php echo str_replace('per_', '', $leg_to_dest['delivery_rate_unit']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($leg_to_dest['total_freight_cost'])): ?>
                        <div class="leg-cost">
                            <strong>$<?php echo number_format($leg_to_dest['total_freight_cost'], 2); ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if ($can_edit): ?>
                        <div class="leg-edit-hint">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Click to edit
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- DESTINATION -->
        <div class="journey-stop destination-stop">
            <div class="stop-marker">
                <div class="marker-icon marker-destination">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                </div>
                <span class="marker-label">DESTINATION</span>
            </div>
            <div class="stop-content">
                <?php if ($destination): ?>
                    <div class="stop-name"><?php echo htmlspecialchars($destination['location_name']); ?></div>
                    <?php if (!empty($destination['location_address'])): ?>
                        <div class="stop-address"><?php echo htmlspecialchars($destination['location_address']); ?></div>
                    <?php endif; ?>
                    <div class="stop-milestone">
                        <span class="milestone-trigger milestone-complete">Project Delivery milestone completes here</span>
                    </div>
                <?php else: ?>
                    <div class="stop-empty">
                        <span>Destination will be set from project address</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($can_edit): ?>
    <div class="add-stop-section">
        <button type="button" class="btn btn-add-stop" onclick="addWarehouseStop()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Warehouse Stop
        </button>
    </div>
    <?php endif; ?>
</div>

<style>
.journey-planner {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
    border: 1px solid rgba(72, 140, 154, 0.1);
    margin-bottom: 24px;
    overflow: hidden;
}

.planner-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.planner-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.1em;
    font-weight: 600;
    color: #293E4C;
}

.planner-title svg {
    color: #488C9A;
}

/* Help Tooltip */
.planner-help {
    position: relative;
}

.help-tooltip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85em;
    color: #6c757d;
    transition: all 0.2s ease;
}

.help-tooltip:hover {
    background: #e9ecef;
    color: #495057;
}

.help-tooltip:hover .help-dropdown {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.help-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 8px;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    padding: 16px;
    width: 320px;
    z-index: 100;
    opacity: 0;
    transform: translateY(-8px);
    transition: all 0.2s ease;
}

.help-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f1f3f5;
}

.help-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.help-item:first-child {
    padding-top: 0;
}

.help-icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.help-item div:last-child {
    font-size: 0.9em;
    line-height: 1.4;
}

.help-item strong {
    color: #293E4C;
    display: block;
}

.help-item span {
    color: #6c757d;
}

.journey-flow {
    padding: 24px;
}

/* Stop Styles */
.journey-stop {
    display: flex;
    gap: 20px;
    margin-bottom: 0;
    position: relative;
}

.stop-marker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    min-width: 60px;
}

.marker-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.marker-origin {
    background: linear-gradient(135deg, #28a745 0%, #20883a 100%);
}

.marker-warehouse {
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
}

.marker-destination {
    background: linear-gradient(135deg, #dc3545 0%, #b02a37 100%);
}

.marker-label {
    font-size: 0.7em;
    font-weight: 700;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stop-content {
    flex: 1;
    background: #f8f9fa;
    border-radius: 12px;
    padding: 16px 20px;
    border: 1px solid #e9ecef;
}

.stop-name-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 4px;
}

.stop-name {
    font-weight: 600;
    color: #293E4C;
    font-size: 1.05em;
}

.stop-address {
    font-size: 0.9em;
    color: #6c757d;
    margin-bottom: 8px;
}

.customs-badge {
    background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
    color: #212529;
    padding: 4px 10px;
    border-radius: 10px;
    font-size: 0.8em;
    font-weight: 600;
}

.stop-dates {
    display: flex;
    gap: 20px;
    font-size: 0.9em;
    color: #495057;
    margin: 8px 0;
}

.stop-fees {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed #dee2e6;
}

.fees-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.fees-title {
    font-weight: 600;
    font-size: 0.9em;
    color: #293E4C;
}

.fees-total {
    font-weight: 700;
    color: #488C9A;
}

.fees-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.fee-item {
    display: flex;
    justify-content: space-between;
    font-size: 0.85em;
    color: #6c757d;
}

.stop-milestone {
    margin-top: 10px;
}

.milestone-trigger {
    font-size: 0.85em;
    color: #488C9A;
    font-style: italic;
}

.milestone-complete {
    color: #28a745;
}

.stop-empty {
    color: #6c757d;
    font-style: italic;
}

.stop-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
}

.btn-edit-stop,
.btn-delete-stop {
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    font-size: 0.85em;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-edit-stop {
    background: #e9ecef;
    color: #495057;
}

.btn-edit-stop:hover {
    background: #dee2e6;
}

.btn-delete-stop {
    background: transparent;
    color: #dc3545;
}

.btn-delete-stop:hover {
    background: #f8d7da;
}

/* Leg Styles */
.journey-leg {
    display: flex;
    gap: 20px;
    margin: 16px 0;
    position: relative;
}

.leg-connector {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 60px;
}

.leg-line {
    width: 3px;
    height: 20px;
    background: linear-gradient(180deg, #488C9A 0%, #3A6E7F 100%);
}

.leg-arrow {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    border: 2px solid #488C9A;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #488C9A;
}

.leg-content {
    flex: 1;
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(58, 110, 127, 0.08) 100%);
    border: 2px dashed rgba(72, 140, 154, 0.3);
    border-radius: 12px;
    padding: 16px 20px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.leg-content:hover {
    border-color: #488C9A;
    box-shadow: 0 4px 12px rgba(72, 140, 154, 0.15);
}

.leg-content.delivery-leg {
    background: linear-gradient(135deg, rgba(220, 53, 69, 0.08) 0%, rgba(176, 42, 55, 0.08) 100%);
    border-color: rgba(220, 53, 69, 0.3);
}

.leg-content.delivery-leg:hover {
    border-color: #dc3545;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.15);
}

.leg-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    flex-wrap: wrap;
    gap: 8px;
}

.leg-type {
    font-weight: 600;
    color: #293E4C;
}

.leg-milestone-badge {
    background: #d4edda;
    color: #155724;
    padding: 4px 10px;
    border-radius: 10px;
    font-size: 0.8em;
    font-weight: 600;
}

.leg-milestone-badge.delivery-milestone {
    background: #f8d7da;
    color: #721c24;
}

.leg-details {
    display: flex;
    gap: 16px;
    font-size: 0.9em;
    color: #495057;
}

.leg-cost {
    margin-top: 8px;
    font-size: 1.1em;
    color: #488C9A;
}

.leg-edit-hint {
    font-size: 0.8em;
    color: #6c757d;
    margin-top: 8px;
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Direct delivery leg (no intermediate stops) */
.direct-leg {
    margin: 24px 0;
}

.direct-leg .leg-line-long {
    height: 40px;
}

/* Unconfigured leg state */
.leg-content.leg-unconfigured {
    background: linear-gradient(135deg, rgba(224, 127, 58, 0.1) 0%, rgba(224, 127, 58, 0.05) 100%);
    border-color: #E07F3A;
    border-style: dashed;
}

.leg-content.leg-unconfigured:hover {
    border-color: #E07F3A;
    box-shadow: 0 4px 16px rgba(224, 127, 58, 0.2);
    background: linear-gradient(135deg, rgba(224, 127, 58, 0.15) 0%, rgba(224, 127, 58, 0.1) 100%);
}

.leg-unconfigured-prompt {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 8px 0;
}

.leg-unconfigured-prompt svg {
    color: #E07F3A;
    flex-shrink: 0;
}

.leg-unconfigured-prompt .prompt-text {
    flex: 1;
}

.leg-unconfigured-prompt .prompt-text strong {
    display: block;
    color: #293E4C;
    font-size: 1.05em;
    margin-bottom: 2px;
}

.leg-unconfigured-prompt .prompt-text span {
    font-size: 0.9em;
    color: #6c757d;
}

.btn-configure-leg {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    background: linear-gradient(135deg, #E07F3A 0%, #d06a25 100%);
    border: none;
    border-radius: 8px;
    color: white;
    font-weight: 600;
    font-size: 0.9em;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.btn-configure-leg:hover {
    background: linear-gradient(135deg, #d06a25 0%, #c05a15 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(224, 127, 58, 0.3);
}

/* Add Stop Button */
.add-stop-section {
    padding: 16px 24px;
    border-top: 1px solid #e9ecef;
    text-align: center;
}

.btn-add-stop {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.1) 100%);
    border: 2px dashed #488C9A;
    border-radius: 10px;
    color: #488C9A;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-add-stop:hover {
    background: linear-gradient(135deg, rgba(72, 140, 154, 0.2) 0%, rgba(58, 110, 127, 0.2) 100%);
    border-style: solid;
}

@media (max-width: 768px) {
    .journey-stop,
    .journey-leg {
        flex-direction: column;
        gap: 12px;
    }

    .stop-marker,
    .leg-connector {
        flex-direction: row;
        min-width: auto;
    }

    .leg-line {
        width: 20px;
        height: 3px;
    }
}
</style>

<script>
function editStop(stopId) {
    // Open stop editor modal
    if (typeof openStopEditorModal === 'function') {
        openStopEditorModal(stopId);
    } else {
        console.log('Edit stop:', stopId);
    }
}

function deleteStop(stopId) {
    if (!confirm('Are you sure you want to remove this stop? This will also remove associated legs.')) {
        return;
    }

    if (typeof removeStop === 'function') {
        removeStop(stopId);
    } else {
        console.log('Delete stop:', stopId);
    }
}

function editLeg(legId, fromStopId, toStopId) {
    // Open leg editor modal with optional stop IDs for new legs
    if (typeof openLegEditorModal === 'function') {
        openLegEditorModal(legId, fromStopId, toStopId);
    } else {
        console.log('Edit leg:', legId, 'from:', fromStopId, 'to:', toStopId);
    }
}

function addWarehouseStop() {
    if (typeof openAddStopModal === 'function') {
        openAddStopModal();
    } else {
        console.log('Add warehouse stop');
    }
}
</script>
