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
 * @param int|null $user_id User who triggered the change
 * @return bool Success status
 */
function save_module_milestones($module_id, $milestones, $conn, $user_id = null) {
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

    $trigger_events = array_values(array_unique(array_filter(array_column($milestones, 'trigger_event'))));
    if (in_array('po_execution', $trigger_events, true)) {
        trigger_batch_milestone($module_id, 'po_execution', $conn, $user_id);
    }

    backfill_delivery_milestones_for_batch($module_id, $conn, $user_id);

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
 * Determine which milestone events apply based on delivery status/dates.
 *
 * @param string|null $delivery_status
 * @param string|null $customs_cleared_date
 * @param string|null $actual_delivery_date
 * @param string|null $origin_type
 * @return array Array of trigger_event strings
 */
function get_delivery_milestone_events(
    $delivery_status,
    $customs_cleared_date = null,
    $actual_delivery_date = null,
    $origin_type = null
) {
    $events = [];

    $status = trim((string)$delivery_status);
    $non_shipping_statuses = ['Pending', 'At Manufacturer', 'Canceled', 'Cancelled', ''];
    $origin = strtolower(trim((string)$origin_type));
    $shipping_allowed = ($origin === '' || $origin !== 'warehouse');

    if ($shipping_allowed && $status !== '' && !in_array($status, $non_shipping_statuses, true)) {
        $events[] = 'shipping';
    }

    if (!empty($customs_cleared_date) || in_array($status, [
        'Cleared Customs',
        'In Transit to Warehouse',
        'Delivered to Warehouse',
        'In Transit to Project',
        'Delivered to Project'
    ], true)) {
        $events[] = 'customs_cleared';
    }

    if (!empty($actual_delivery_date) || $status === 'Delivered to Project') {
        $events[] = 'project_delivery';
    }

    return array_values(array_unique($events));
}

/**
 * Trigger delivery milestones based on current delivery status/dates.
 *
 * @param int $delivery_id Delivery ID
 * @param string|null $delivery_status Status of delivery
 * @param mysqli $conn Database connection
 * @param int|null $user_id User who triggered the change
 * @param string|null $customs_cleared_date Customs cleared date if available
 * @param string|null $actual_delivery_date Actual delivery date if available
 * @param string|null $origin_type Origin type if available
 * @return array Trigger results keyed by event
 */
function trigger_delivery_milestones_for_status(
    $delivery_id,
    $delivery_status,
    $conn,
    $user_id = null,
    $customs_cleared_date = null,
    $actual_delivery_date = null,
    $origin_type = null
) {
    $results = [];

    if (!$delivery_id || !$conn) {
        return $results;
    }

    if ($origin_type === null || $customs_cleared_date === null || $actual_delivery_date === null) {
        $stmt_delivery = $conn->prepare("SELECT origin_type, customs_cleared_date, actual_delivery_date FROM deliveries WHERE id = ? LIMIT 1");
        if ($stmt_delivery) {
            $stmt_delivery->bind_param("i", $delivery_id);
            $stmt_delivery->execute();
            $stmt_delivery->bind_result($db_origin_type, $db_customs_cleared_date, $db_actual_delivery_date);
            if ($stmt_delivery->fetch()) {
                if ($origin_type === null) {
                    $origin_type = $db_origin_type;
                }
                if ($customs_cleared_date === null) {
                    $customs_cleared_date = $db_customs_cleared_date;
                }
                if ($actual_delivery_date === null) {
                    $actual_delivery_date = $db_actual_delivery_date;
                }
            }
            $stmt_delivery->close();
        }
    }

    $events = get_delivery_milestone_events($delivery_status, $customs_cleared_date, $actual_delivery_date, $origin_type);
    foreach ($events as $event) {
        $results[$event] = trigger_delivery_milestone($delivery_id, $event, $conn, $user_id);
    }

    return $results;
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

    $origin_type = '';
    $stmt_origin = $conn->prepare("SELECT origin_type FROM deliveries WHERE id = ? LIMIT 1");
    if ($stmt_origin) {
        $stmt_origin->bind_param("i", $delivery_id);
        $stmt_origin->execute();
        $stmt_origin->bind_result($origin_type);
        $stmt_origin->fetch();
        $stmt_origin->close();
    }
    $origin_type = strtolower(trim((string)$origin_type));
    $shipping_allowed = ($origin_type === '' || $origin_type !== 'warehouse');

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
            if (($t['trigger_event'] ?? '') === 'shipping' && !$shipping_allowed) {
                continue;
            }
            $result['triggered'][] = $t;
            $result['total_triggered'] += floatval($t['payment_amount']);
            $triggered_ids[] = $t['milestone_id'];
        }

        // Calculate pending milestones
        foreach ($milestones as $m) {
            if (($m['trigger_event'] ?? '') === 'shipping' && !$shipping_allowed) {
                continue;
            }
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
 * Get all triggered milestone instances for a project.
 * Includes delivery and module batch info for display.
 *
 * @param int $project_id Project ID
 * @param mysqli $conn Database connection
 * @return array Array of milestone instances with details
 */
function get_project_milestone_instances($project_id, $conn) {
    if (!$project_id || !$conn) {
        return [];
    }

    // Get delivery-level milestones
    $stmt = $conn->prepare("
        SELECT
            dmi.id,
            dmi.delivery_id,
            dmi.module_batch_id,
            dmi.triggered_at,
            dmi.module_quantity,
            dmi.wattage,
            dmi.cost_per_watt,
            dmi.milestone_percentage,
            dmi.payment_amount,
            mbm.milestone_name,
            mbm.trigger_event,
            d.bol_number,
            d.supplier as manufacturer,
            m.vendor_name as batch_vendor
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        LEFT JOIN deliveries d ON dmi.delivery_id = d.id
        LEFT JOIN modules m ON dmi.module_batch_id = m.id OR mbm.module_id = m.id
        WHERE d.project_id = ? OR m.project_id = ?
        ORDER BY dmi.triggered_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $project_id, $project_id);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $results;
}

/**
 * Get milestone payment timeline for a project.
 * Sorted by date with cumulative totals.
 *
 * @param int $project_id Project ID
 * @param mysqli $conn Database connection
 * @return array Timeline entries with cumulative amounts
 */
function get_milestone_payment_timeline($project_id, $conn) {
    $instances = get_project_milestone_instances($project_id, $conn);

    $timeline = [];
    $cumulative = 0.0;

    // Sort by triggered_at ascending for timeline
    usort($instances, function($a, $b) {
        return strtotime($a['triggered_at']) - strtotime($b['triggered_at']);
    });

    foreach ($instances as $instance) {
        $amount = floatval($instance['payment_amount']);
        $cumulative += $amount;

        $timeline[] = [
            'date' => $instance['triggered_at'],
            'event_type' => 'milestone',
            'trigger_event' => $instance['trigger_event'],
            'description' => $instance['milestone_name'] . ($instance['bol_number'] ? ' - ' . $instance['bol_number'] : ''),
            'amount' => $amount,
            'cumulative' => $cumulative,
            'delivery_id' => $instance['delivery_id'],
            'module_batch_id' => $instance['module_batch_id']
        ];
    }

    return $timeline;
}

/**
 * Get total contract value for a project (based on configured milestones).
 * Returns total module value that has milestones configured.
 *
 * @param int $project_id Project ID
 * @param mysqli $conn Database connection
 * @return array ['total_contract_value' => X, 'total_triggered' => X, 'total_remaining' => X, 'completion_percent' => X]
 */
function get_milestone_completion_status($project_id, $conn) {
    $result = [
        'total_contract_value' => 0.0,
        'total_triggered' => 0.0,
        'total_remaining' => 0.0,
        'completion_percent' => 0.0,
        'batches' => []
    ];

    if (!$project_id || !$conn) {
        return $result;
    }

    // Get all module batches for this project that have milestones configured
    $stmt = $conn->prepare("
        SELECT
            m.id as module_id,
            m.vendor_name,
            m.cost_per_watt,
            SUM(umi.wattage * umi.quantity) as total_watt_modules,
            SUM(umi.quantity) as total_quantity,
            (SELECT SUM(percentage) FROM module_batch_milestones WHERE module_id = m.id AND is_active = 1) as total_milestone_percent
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
          AND m.cost_per_watt IS NOT NULL
          AND m.cost_per_watt > 0
          AND EXISTS (SELECT 1 FROM module_batch_milestones WHERE module_id = m.id AND is_active = 1)
        GROUP BY m.id
    ");

    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($batches as $batch) {
        $batch_value = floatval($batch['cost_per_watt']) * floatval($batch['total_watt_modules']);
        $result['total_contract_value'] += $batch_value;

        $result['batches'][] = [
            'module_id' => $batch['module_id'],
            'vendor_name' => $batch['vendor_name'],
            'batch_value' => $batch_value,
            'total_quantity' => $batch['total_quantity'],
            'milestone_percent_configured' => floatval($batch['total_milestone_percent'])
        ];
    }

    // Get total triggered amount
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN mbm.trigger_event = 'shipping' AND d.origin_type = 'warehouse' THEN 0
                ELSE dmi.payment_amount
            END
        ), 0) as total_triggered
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        JOIN modules m ON mbm.module_id = m.id
        LEFT JOIN deliveries d ON dmi.delivery_id = d.id
        WHERE m.project_id = ?
    ");

    if ($stmt) {
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $triggered_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $result['total_triggered'] = floatval($triggered_result['total_triggered'] ?? 0);
    }

    $result['total_remaining'] = $result['total_contract_value'] - $result['total_triggered'];
    $result['completion_percent'] = $result['total_contract_value'] > 0
        ? round(($result['total_triggered'] / $result['total_contract_value']) * 100, 1)
        : 0.0;

    return $result;
}

/**
 * Get portfolio-wide milestone summary across all projects.
 * For use on module_cost_analysis.php.
 *
 * @param mysqli $conn Database connection
 * @param int|null $account_id Optional account filter
 * @return array Portfolio summary
 */
function get_portfolio_milestone_summary($conn, $account_id = null) {
    $result = [
        'total_contract_value' => 0.0,
        'total_triggered' => 0.0,
        'total_remaining' => 0.0,
        'completion_percent' => 0.0,
        'by_trigger_event' => [
            'po_execution' => ['total' => 0.0, 'count' => 0],
            'shipping' => ['total' => 0.0, 'count' => 0],
            'customs_cleared' => ['total' => 0.0, 'count' => 0],
            'project_delivery' => ['total' => 0.0, 'count' => 0]
        ],
        'projects_with_milestones' => 0,
        'total_projects' => 0
    ];

    if (!$conn) {
        return $result;
    }

    // Build account filter
    $account_filter = '';
    $params = [];
    $types = '';

    if ($account_id) {
        $account_filter = 'AND p.account_id = ?';
        $params[] = $account_id;
        $types = 'i';
    }

    // Get total contract value (all module batches with milestones)
    $sql = "
        SELECT
            COUNT(DISTINCT p.id) as project_count,
            COUNT(DISTINCT CASE WHEN mbm.id IS NOT NULL THEN p.id END) as projects_with_milestones,
            COALESCE(SUM(
                CASE WHEN mbm.id IS NOT NULL AND m.cost_per_watt > 0
                THEN m.cost_per_watt * umi.wattage * umi.quantity
                ELSE 0 END
            ), 0) as total_contract_value
        FROM projects p
        LEFT JOIN modules m ON m.project_id = p.id
        LEFT JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        LEFT JOIN module_batch_milestones mbm ON mbm.module_id = m.id AND mbm.is_active = 1
        WHERE (p.status IS NULL OR p.status = 'active') $account_filter
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $result['total_projects'] = (int)($totals['project_count'] ?? 0);
        $result['projects_with_milestones'] = (int)($totals['projects_with_milestones'] ?? 0);
        // Note: total_contract_value is counted multiple times per milestone, need to dedupe
    }

    // Get actual contract value (unique module batches)
    $sql = "
        SELECT COALESCE(SUM(batch_value), 0) as total_value
        FROM (
            SELECT DISTINCT m.id, (m.cost_per_watt * SUM(umi.wattage * umi.quantity)) as batch_value
            FROM modules m
            JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
            JOIN projects p ON m.project_id = p.id
            WHERE m.cost_per_watt IS NOT NULL
              AND m.cost_per_watt > 0
              AND EXISTS (SELECT 1 FROM module_batch_milestones WHERE module_id = m.id AND is_active = 1)
              AND (p.status IS NULL OR p.status = 'active')
              $account_filter
            GROUP BY m.id
        ) as batches
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $value_result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $result['total_contract_value'] = floatval($value_result['total_value'] ?? 0);
    }

    // Get triggered amounts by event type
    $sql = "
        SELECT
            mbm.trigger_event,
            COALESCE(SUM(dmi.payment_amount), 0) as total_amount,
            COUNT(dmi.id) as instance_count
        FROM delivery_milestone_instances dmi
        JOIN module_batch_milestones mbm ON dmi.milestone_id = mbm.id
        JOIN modules m ON mbm.module_id = m.id
        JOIN projects p ON m.project_id = p.id
        WHERE (p.status IS NULL OR p.status = 'active') $account_filter
        GROUP BY mbm.trigger_event
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($events as $event) {
            $trigger = $event['trigger_event'];
            $amount = floatval($event['total_amount']);
            $count = intval($event['instance_count']);
            if (isset($result['by_trigger_event'][$trigger])) {
                $result['by_trigger_event'][$trigger] = [
                    'total' => $amount,
                    'count' => $count
                ];
            }
            $result['total_triggered'] += $amount;
        }
    }

    $result['total_remaining'] = $result['total_contract_value'] - $result['total_triggered'];
    $result['completion_percent'] = $result['total_contract_value'] > 0
        ? round(($result['total_triggered'] / $result['total_contract_value']) * 100, 1)
        : 0.0;

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

    // Get module batch cost_per_watt and po_execution_date
    $stmt = $conn->prepare("SELECT cost_per_watt, po_execution_date FROM modules WHERE id = ?");
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
    $po_execution_date = $module_result['po_execution_date'] ?? null;

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

    // Process each milestone - use po_execution_date if available for po_execution events
    $triggered_at_value = ($trigger_event === 'po_execution' && !empty($po_execution_date)) ? $po_execution_date : date('Y-m-d H:i:s');
    $insert_stmt = $conn->prepare("
        INSERT INTO delivery_milestone_instances
        (delivery_id, module_batch_id, milestone_id, triggered_at, triggered_by_user_id, module_quantity, wattage, cost_per_watt, milestone_percentage, payment_amount)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
            "iisiiiddd",
            $module_batch_id,
            $milestone_id,
            $triggered_at_value,
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

/**
 * Backfill delivery-level milestones for an existing module batch.
 * Useful when milestones are added after deliveries already exist.
 *
 * @param int $module_batch_id Module batch ID
 * @param mysqli $conn Database connection
 * @param int|null $user_id User who triggered the change
 * @return array ['success' => bool, 'triggered_count' => int]
 */
function backfill_delivery_milestones_for_batch($module_batch_id, $conn, $user_id = null) {
    $result = [
        'success' => false,
        'triggered_count' => 0
    ];

    if (!$module_batch_id || !$conn) {
        return $result;
    }

    $milestones = get_module_milestones($module_batch_id, $conn);
    if (empty($milestones)) {
        $result['success'] = true;
        return $result;
    }

    $events = array_unique(array_column($milestones, 'trigger_event'));
    $events = array_values(array_intersect($events, ['shipping', 'customs_cleared', 'project_delivery']));
    if (empty($events)) {
        $result['success'] = true;
        return $result;
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT d.id, d.status_of_delivery, d.customs_cleared_date, d.actual_delivery_date, d.origin_type
        FROM deliveries d
        JOIN delivery_pallets dp ON dp.delivery_id = d.id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        WHERE umi.unassigned_module_id = ?
    ");

    if (!$stmt) {
        return $result;
    }

    $stmt->bind_param('i', $module_batch_id);
    $stmt->execute();
    $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($deliveries as $delivery) {
        $delivery_id = (int)$delivery['id'];
        $status = $delivery['status_of_delivery'] ?? '';

        if ($status === 'Canceled') {
            continue;
        }

        $origin_type = strtolower(trim((string)($delivery['origin_type'] ?? '')));
        $shipping_allowed = ($origin_type === '' || $origin_type !== 'warehouse');

        foreach ($events as $event) {
            $should_trigger = false;

            if ($event === 'shipping') {
                $should_trigger = $shipping_allowed;
            } elseif ($event === 'customs_cleared') {
                if (!empty($delivery['customs_cleared_date'])) {
                    $should_trigger = true;
                } elseif (in_array($status, [
                    'Cleared Customs',
                    'In Transit to Warehouse',
                    'Delivered to Warehouse',
                    'In Transit to Project',
                    'Delivered to Project'
                ], true)) {
                    $should_trigger = true;
                }
            } elseif ($event === 'project_delivery') {
                if (!empty($delivery['actual_delivery_date']) || $status === 'Delivered to Project') {
                    $should_trigger = true;
                }
            }

            if ($should_trigger) {
                $trigger = trigger_delivery_milestone($delivery_id, $event, $conn, $user_id);
                if (!empty($trigger['triggered'])) {
                    $result['triggered_count'] += count($trigger['triggered']);
                }
            }
        }
    }

    $result['success'] = true;
    return $result;
}

/**
 * Get milestones for a projection-only module.
 * Uses the projection_module_milestones table instead of module_batch_milestones.
 */
function get_projection_module_milestones($projection_module_id, $conn) {
    if (!$projection_module_id || !$conn) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT id, milestone_name, trigger_event, percentage, display_order, is_active
        FROM projection_module_milestones
        WHERE projection_module_id = ? AND is_active = 1
        ORDER BY display_order, id
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $projection_module_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

/**
 * Save milestones for a projection-only module.
 * Uses the projection_module_milestones table.
 */
function save_projection_module_milestones($projection_module_id, $milestones, $conn) {
    if (!$projection_module_id || !$conn) {
        return false;
    }

    // Delete existing milestones
    $stmt = $conn->prepare("DELETE FROM projection_module_milestones WHERE projection_module_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $projection_module_id);
    $stmt->execute();
    $stmt->close();

    if (empty($milestones)) {
        return true;
    }

    $stmt = $conn->prepare("
        INSERT INTO projection_module_milestones (projection_module_id, milestone_name, trigger_event, percentage, display_order)
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

        $stmt->bind_param("issdi", $projection_module_id, $name, $trigger, $percentage, $order);
        $stmt->execute();
        $order++;
    }

    $stmt->close();
    return true;
}
?>
