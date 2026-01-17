<?php
/**
 * Helper functions for managing payment milestones.
 * Handles milestone configuration, triggering, and calculations.
 */

/**
 * Calculate milestone payment amount.
 *
 * @param float|null $cost_per_watt Module batch cost per watt
 * @param int $wattage Module wattage
 * @param int $quantity Number of modules
 * @param float $percentage Milestone percentage (0-100)
 * @return float Payment amount
 */
function calculate_milestone_payment($cost_per_watt, $wattage, $quantity, $percentage) {
    if (!$cost_per_watt || $cost_per_watt <= 0 || $percentage <= 0) {
        return 0.0;
    }
    $total_watts = $wattage * $quantity;
    $total_module_value = $cost_per_watt * $total_watts;
    return round($total_module_value * ($percentage / 100), 2);
}

/**
 * Get milestones configured for a module batch.
 *
 * @param int $module_id Module batch ID
 * @param mysqli $conn Database connection
 * @return array Array of milestone configurations
 */
function get_module_milestones($module_id, $conn) {
    if (!$module_id || !$conn) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, milestone_name, trigger_event, percentage, display_order, is_active
        FROM module_batch_milestones
        WHERE module_id = ? AND is_active = 1
        ORDER BY display_order, id
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $module_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

/**
 * Save milestones for a module batch.
 * Replaces all existing milestones with the new set.
 *
 * @param int $module_id Module batch ID
 * @param array $milestones Array of milestone data: [['trigger_event' => 'po_execution', 'percentage' => 30], ...]
 * @param mysqli $conn Database connection
 * @return bool Success status
 */
function save_module_milestones($module_id, $milestones, $conn) {
    if (!$module_id || !$conn) {
        return false;
    }

    // Delete existing milestones
    $stmt = $conn->prepare("DELETE FROM module_batch_milestones WHERE module_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $module_id);
    $stmt->execute();
    $stmt->close();

    // Insert new milestones
    if (empty($milestones)) {
        return true; // No milestones to insert is valid
    }

    $stmt = $conn->prepare("
        INSERT INTO module_batch_milestones (module_id, milestone_name, trigger_event, percentage, display_order)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return false;
    }

    $trigger_names = [
        'po_execution' => 'PO Execution',
        'shipping' => 'Shipping',
        'customs_cleared' => 'Customs Clearance',
        'project_delivery' => 'Project Delivery'
    ];

    $order = 1;
    foreach ($milestones as $milestone) {
        $trigger = $milestone['trigger_event'] ?? '';
        $percentage = floatval($milestone['percentage'] ?? 0);

        if (empty($trigger) || $percentage <= 0) {
            continue;
        }

        $name = $milestone['milestone_name'] ?? ($trigger_names[$trigger] ?? $trigger);

        $stmt->bind_param("issdi", $module_id, $name, $trigger, $percentage, $order);
        $stmt->execute();
        $order++;
    }

    $stmt->close();
    return true;
}

/**
 * Get module batch info for a delivery by tracing through pallets.
 * Returns the first module batch found (deliveries typically have one batch).
 *
 * @param int $delivery_id Delivery ID
 * @param mysqli $conn Database connection
 * @return array|null Module info: ['module_id' => X, 'cost_per_watt' => X] or null
 */
function get_delivery_module_batch($delivery_id, $conn) {
    if (!$delivery_id || !$conn) {
        return null;
    }

    // Trace: delivery -> delivery_pallets -> inventory_pallets -> unassigned_module_items -> modules
    $stmt = $conn->prepare("
        SELECT DISTINCT m.id AS module_id, m.cost_per_watt
        FROM delivery_pallets dp
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE dp.delivery_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $delivery_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result;
}

/**
 * Get delivery details needed for milestone calculation.
 *
 * @param int $delivery_id Delivery ID
 * @param mysqli $conn Database connection
 * @return array Delivery info: ['wattage' => X, 'quantity' => X]
 */
function get_delivery_details_for_milestone($delivery_id, $conn) {
    if (!$delivery_id || !$conn) {
        return ['wattage' => 0, 'quantity' => 0];
    }

    // Get wattage and quantity from delivery record
    $stmt = $conn->prepare("SELECT wattage, quantity FROM deliveries WHERE id = ?");
    if (!$stmt) {
        return ['wattage' => 0, 'quantity' => 0];
    }

    $stmt->bind_param("i", $delivery_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        return ['wattage' => 0, 'quantity' => 0];
    }

    return [
        'wattage' => (int)($result['wattage'] ?? 0),
        'quantity' => (int)($result['quantity'] ?? 0)
    ];
}

/**
 * Check if a milestone has already been triggered for a delivery.
 *
 * @param int $delivery_id Delivery ID
 * @param int $milestone_id Milestone ID
 * @param mysqli $conn Database connection
 * @return bool True if already triggered
 */
function is_milestone_triggered($delivery_id, $milestone_id, $conn) {
    if (!$delivery_id || !$milestone_id || !$conn) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id FROM delivery_milestone_instances
        WHERE delivery_id = ? AND milestone_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $delivery_id, $milestone_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result !== null;
}

/**
 * Trigger milestone(s) for a delivery based on event.
 * Finds module batch for delivery, checks for configured milestones matching trigger_event,
 * and creates instance records if not already triggered.
 *
 * @param int $delivery_id Delivery ID
 * @param string $trigger_event Event type: 'po_execution', 'customs_cleared', 'warehouse_arrival', 'project_delivery'
 * @param mysqli $conn Database connection
 * @param int|null $user_id User who triggered the change
 * @return array Result: ['success' => bool, 'triggered' => [...], 'total_amount' => X, 'error' => string|null]
 */
function trigger_delivery_milestone($delivery_id, $trigger_event, $conn, $user_id = null) {
    $result = [
        'success' => false,
        'triggered' => [],
        'total_amount' => 0.0,
        'error' => null
    ];

    if (!$delivery_id || !$trigger_event || !$conn) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // Get module batch for this delivery
    $module_info = get_delivery_module_batch($delivery_id, $conn);
    if (!$module_info) {
        // No module batch linked - milestones don't apply (this is OK, not an error)
        $result['success'] = true;
        return $result;
    }

    $module_id = $module_info['module_id'];
    $cost_per_watt = $module_info['cost_per_watt'];

    // No cost configured - milestones don't apply
    if (!$cost_per_watt || $cost_per_watt <= 0) {
        $result['success'] = true;
        return $result;
    }

    // Get milestones for this module batch matching the trigger event
    $stmt = $conn->prepare("
        SELECT id, milestone_name, trigger_event, percentage
        FROM module_batch_milestones
        WHERE module_id = ? AND trigger_event = ? AND is_active = 1
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stmt->bind_param("is", $module_id, $trigger_event);
    $stmt->execute();
    $milestones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($milestones)) {
        // No milestones configured for this event - that's OK
        $result['success'] = true;
        return $result;
    }

    // Get delivery details
    $delivery_details = get_delivery_details_for_milestone($delivery_id, $conn);
    $wattage = $delivery_details['wattage'];
    $quantity = $delivery_details['quantity'];

    if ($wattage <= 0 || $quantity <= 0) {
        $result['success'] = true;
        return $result;
    }

    // Process each milestone
    $insert_stmt = $conn->prepare("
        INSERT INTO delivery_milestone_instances
        (delivery_id, milestone_id, triggered_at, triggered_by_user_id, module_quantity, wattage, cost_per_watt, milestone_percentage, payment_amount)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)
    ");

    if (!$insert_stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    foreach ($milestones as $milestone) {
        $milestone_id = $milestone['id'];

        // Check if already triggered
        if (is_milestone_triggered($delivery_id, $milestone_id, $conn)) {
            continue;
        }

        $percentage = floatval($milestone['percentage']);
        $payment_amount = calculate_milestone_payment($cost_per_watt, $wattage, $quantity, $percentage);

        $insert_stmt->bind_param(
            "iiiiiddd",
            $delivery_id,
            $milestone_id,
            $user_id,
            $quantity,
            $wattage,
            $cost_per_watt,
            $percentage,
            $payment_amount
        );

        if ($insert_stmt->execute()) {
            $result['triggered'][] = [
                'milestone_id' => $milestone_id,
                'milestone_name' => $milestone['milestone_name'],
                'percentage' => $percentage,
                'payment_amount' => $payment_amount
            ];
            $result['total_amount'] += $payment_amount;
        }
    }

    $insert_stmt->close();
    $result['success'] = true;

    return $result;
}

/**
 * Get milestone status for a delivery.
 * Returns configured milestones and which have been triggered.
 *
 * @param int $delivery_id Delivery ID
 * @param mysqli $conn Database connection
 * @return array ['configured' => [...], 'triggered' => [...], 'pending' => [...], 'total_triggered' => X, 'total_pending' => X]
 */
function get_delivery_milestone_status($delivery_id, $conn) {
    $result = [
        'configured' => [],
        'triggered' => [],
        'pending' => [],
        'total_triggered' => 0.0,
        'total_pending' => 0.0,
        'has_milestones' => false
    ];

    if (!$delivery_id || !$conn) {
        return $result;
    }

    // Get module batch for this delivery
    $module_info = get_delivery_module_batch($delivery_id, $conn);
    if (!$module_info) {
        return $result;
    }

    $module_id = $module_info['module_id'];
    $cost_per_watt = $module_info['cost_per_watt'];

    // Get all configured milestones for this module batch
    $milestones = get_module_milestones($module_id, $conn);
    if (empty($milestones)) {
        return $result;
    }

    $result['has_milestones'] = true;
    $result['configured'] = $milestones;

    // Get delivery details for calculating pending amounts
    $delivery_details = get_delivery_details_for_milestone($delivery_id, $conn);
    $wattage = $delivery_details['wattage'];
    $quantity = $delivery_details['quantity'];

    // Get triggered instances for this delivery
    $stmt = $conn->prepare("
        SELECT dmi.*, mbm.milestone_name, mbm.trigger_event
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        WHERE dmi.delivery_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param("i", $delivery_id);
        $stmt->execute();
        $triggered = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $triggered_ids = [];
        foreach ($triggered as $t) {
            $result['triggered'][] = $t;
            $result['total_triggered'] += floatval($t['payment_amount']);
            $triggered_ids[] = $t['milestone_id'];
        }

        // Calculate pending milestones
        foreach ($milestones as $m) {
            if (!in_array($m['id'], $triggered_ids)) {
                $pending_amount = calculate_milestone_payment($cost_per_watt, $wattage, $quantity, $m['percentage']);
                $result['pending'][] = array_merge($m, ['estimated_amount' => $pending_amount]);
                $result['total_pending'] += $pending_amount;
            }
        }
    }

    return $result;
}

/**
 * Get milestone summary for a project.
 * Aggregates milestone data across all deliveries for the project.
 *
 * @param int $project_id Project ID
 * @param mysqli $conn Database connection
 * @return array Summary data
 */
function get_project_milestone_summary($project_id, $conn) {
    $result = [
        'total_triggered' => 0.0,
        'total_pending' => 0.0,
        'by_trigger_event' => [
            'po_execution' => ['triggered' => 0.0, 'pending' => 0.0],
            'shipping' => ['triggered' => 0.0, 'pending' => 0.0],
            'customs_cleared' => ['triggered' => 0.0, 'pending' => 0.0],
            'project_delivery' => ['triggered' => 0.0, 'pending' => 0.0]
        ],
        'deliveries_with_milestones' => 0,
        'deliveries_count' => 0
    ];

    if (!$project_id || !$conn) {
        return $result;
    }

    // Get all deliveries for this project
    $stmt = $conn->prepare("SELECT id FROM deliveries WHERE project_id = ?");
    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result['deliveries_count'] = count($deliveries);

    foreach ($deliveries as $delivery) {
        $status = get_delivery_milestone_status($delivery['id'], $conn);

        if ($status['has_milestones']) {
            $result['deliveries_with_milestones']++;
            $result['total_triggered'] += $status['total_triggered'];
            $result['total_pending'] += $status['total_pending'];

            // Aggregate by trigger event
            foreach ($status['triggered'] as $t) {
                $event = $t['trigger_event'] ?? 'other';
                if (isset($result['by_trigger_event'][$event])) {
                    $result['by_trigger_event'][$event]['triggered'] += floatval($t['payment_amount']);
                }
            }
            foreach ($status['pending'] as $p) {
                $event = $p['trigger_event'] ?? 'other';
                if (isset($result['by_trigger_event'][$event])) {
                    $result['by_trigger_event'][$event]['pending'] += floatval($p['estimated_amount']);
                }
            }
        }
    }

    return $result;
}

/**
 * Check if a batch-level milestone has already been triggered.
 *
 * @param int $module_batch_id Module batch ID
 * @param int $milestone_id Milestone ID
 * @param mysqli $conn Database connection
 * @return bool True if already triggered
 */
function is_batch_milestone_triggered($module_batch_id, $milestone_id, $conn) {
    if (!$module_batch_id || !$milestone_id || !$conn) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT id FROM delivery_milestone_instances
        WHERE module_batch_id = ? AND milestone_id = ? AND delivery_id IS NULL
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $module_batch_id, $milestone_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result !== null;
}

/**
 * Trigger batch-level milestone (e.g., PO Execution when module batch is created).
 * Calculates payment based on total batch value (all wattages × quantities × cost_per_watt × percentage).
 *
 * @param int $module_batch_id Module batch ID
 * @param string $trigger_event Event type (typically 'po_execution')
 * @param mysqli $conn Database connection
 * @param int|null $user_id User who triggered the change
 * @return array Result: ['success' => bool, 'triggered' => [...], 'total_amount' => X, 'error' => string|null]
 */
function trigger_batch_milestone($module_batch_id, $trigger_event, $conn, $user_id = null) {
    $result = [
        'success' => false,
        'triggered' => [],
        'total_amount' => 0.0,
        'error' => null
    ];

    if (!$module_batch_id || !$trigger_event || !$conn) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // Get module batch cost_per_watt
    $stmt = $conn->prepare("SELECT cost_per_watt FROM modules WHERE id = ?");
    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stmt->bind_param("i", $module_batch_id);
    $stmt->execute();
    $module_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$module_result) {
        $result['error'] = 'Module batch not found';
        return $result;
    }

    $cost_per_watt = $module_result['cost_per_watt'];

    // No cost configured - milestones don't apply
    if (!$cost_per_watt || $cost_per_watt <= 0) {
        $result['success'] = true;
        return $result;
    }

    // Get total wattage and quantity for this batch
    $stmt = $conn->prepare("
        SELECT SUM(wattage * quantity) as total_watt_modules, SUM(quantity) as total_quantity
        FROM unassigned_module_items
        WHERE unassigned_module_id = ?
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stmt->bind_param("i", $module_batch_id);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total_watt_modules = (int)($totals['total_watt_modules'] ?? 0);
    $total_quantity = (int)($totals['total_quantity'] ?? 0);

    if ($total_watt_modules <= 0 || $total_quantity <= 0) {
        $result['success'] = true;
        return $result;
    }

    // Get milestones for this module batch matching the trigger event
    $stmt = $conn->prepare("
        SELECT id, milestone_name, trigger_event, percentage
        FROM module_batch_milestones
        WHERE module_id = ? AND trigger_event = ? AND is_active = 1
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stmt->bind_param("is", $module_batch_id, $trigger_event);
    $stmt->execute();
    $milestones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($milestones)) {
        // No milestones configured for this event - that's OK
        $result['success'] = true;
        return $result;
    }

    // Process each milestone
    $insert_stmt = $conn->prepare("
        INSERT INTO delivery_milestone_instances
        (delivery_id, module_batch_id, milestone_id, triggered_at, triggered_by_user_id, module_quantity, wattage, cost_per_watt, milestone_percentage, payment_amount)
        VALUES (NULL, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
    ");

    if (!$insert_stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    // For batch-level, use average wattage for display purposes
    $avg_wattage = $total_quantity > 0 ? round($total_watt_modules / $total_quantity) : 0;

    foreach ($milestones as $milestone) {
        $milestone_id = $milestone['id'];

        // Check if already triggered at batch level
        if (is_batch_milestone_triggered($module_batch_id, $milestone_id, $conn)) {
            continue;
        }

        $percentage = floatval($milestone['percentage']);
        // Calculate based on total batch value
        $total_batch_value = $cost_per_watt * $total_watt_modules;
        $payment_amount = round($total_batch_value * ($percentage / 100), 2);

        $insert_stmt->bind_param(
            "iiiiiddd",
            $module_batch_id,
            $milestone_id,
            $user_id,
            $total_quantity,
            $avg_wattage,
            $cost_per_watt,
            $percentage,
            $payment_amount
        );

        if ($insert_stmt->execute()) {
            $result['triggered'][] = [
                'milestone_id' => $milestone_id,
                'milestone_name' => $milestone['milestone_name'],
                'percentage' => $percentage,
                'payment_amount' => $payment_amount
            ];
            $result['total_amount'] += $payment_amount;
        }
    }

    $insert_stmt->close();
    $result['success'] = true;

    return $result;
}
?>
