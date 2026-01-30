<?php
/**
 * Delivery Projection Helper Functions
 * CRUD operations and utility functions for delivery projections (Phase 3)
 *
 * @created 2026-01-19
 */

// Include milestone helpers for cost calculations
require_once __DIR__ . '/milestone_helpers.php';
require_once __DIR__ . '/anticipated_schedule_helpers.php';

/**
 * =====================================================
 * PROJECTION CRUD FUNCTIONS
 * =====================================================
 */

/**
 * Create a new delivery projection for a project.
 *
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @param string $projection_name Name for the projection
 * @param int $user_id User creating the projection
 * @param bool $is_primary Whether this is the primary projection
 * @return array ['success' => bool, 'projection_id' => int|null, 'error' => string|null]
 */
function create_projection($conn, $project_id, $projection_name, $user_id, $is_primary = false) {
    $result = ['success' => false, 'projection_id' => null, 'error' => null];

    if (!$conn || !$project_id || !$user_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // If setting as primary, unset existing primary
    if ($is_primary) {
        $stmt = $conn->prepare("UPDATE delivery_projections SET is_primary = 0 WHERE project_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $is_primary_int = $is_primary ? 1 : 0;
    $stmt = $conn->prepare("
        INSERT INTO delivery_projections (project_id, projection_name, is_primary, created_by)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error: ' . $conn->error;
        return $result;
    }

    $stmt->bind_param("isii", $project_id, $projection_name, $is_primary_int, $user_id);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['projection_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to create projection: ' . $stmt->error;
    }

    $stmt->close();
    return $result;
}

/**
 * Get a projection by ID with all related data.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array|null Projection data or null if not found
 */
function get_projection($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT dp.*,
               COALESCE(p.project_name, dp.general_project_name) AS project_name,
               COALESCE(p.project_address, dp.general_project_address) AS project_address,
               u.username AS created_by_name
        FROM delivery_projections dp
        LEFT JOIN projects p ON p.id = dp.project_id
        LEFT JOIN users u ON u.id = dp.created_by
        WHERE dp.id = ?
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $projection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$projection) {
        return null;
    }

    // Get module allocations
    $projection['module_allocations'] = get_projection_module_allocations($conn, $projection_id);

    // Get stops
    $projection['stops'] = get_projection_stops($conn, $projection_id);

    // Get legs
    $projection['legs'] = get_projection_legs($conn, $projection_id);

    // Get cost summary
    $projection['cost_summary'] = get_projection_cost_summary($conn, $projection_id);

    return $projection;
}

/**
 * Get all projections for a project.
 *
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array List of projections
 */
function get_project_projections($conn, $project_id) {
    if (!$conn || !$project_id) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT dp.*,
               u.username AS created_by_name,
               pcs.grand_total
        FROM delivery_projections dp
        LEFT JOIN users u ON u.id = dp.created_by
        LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id
        WHERE dp.project_id = ? AND dp.is_template = 0
        ORDER BY dp.is_primary DESC, dp.created_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $projections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $projections;
}

/**
 * Get the primary projection for a project.
 *
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array|null Primary projection or null
 */
function get_primary_projection($conn, $project_id) {
    if (!$conn || !$project_id) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id FROM delivery_projections
        WHERE project_id = ? AND is_primary = 1 AND is_template = 0
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) {
        return null;
    }

    return get_projection($conn, $result['id']);
}

/**
 * Update projection basic info.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param array $data Fields to update
 * @return bool Success
 */
function update_projection($conn, $projection_id, $data) {
    if (!$conn || !$projection_id || empty($data)) {
        return false;
    }

    $allowed_fields = ['projection_name', 'status', 'notes', 'is_primary'];
    $updates = [];
    $types = '';
    $values = [];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "`$field` = ?";
            if ($field === 'is_primary') {
                $types .= 'i';
                $values[] = $data[$field] ? 1 : 0;
            } else {
                $types .= 's';
                $values[] = $data[$field];
            }
        }
    }

    if (empty($updates)) {
        return false;
    }

    // If setting as primary, unset others first
    if (isset($data['is_primary']) && $data['is_primary']) {
        $stmt = $conn->prepare("
            UPDATE delivery_projections dp1
            SET is_primary = 0
            WHERE project_id = (SELECT project_id FROM (SELECT project_id FROM delivery_projections WHERE id = ?) AS t)
              AND id != ?
        ");
        if ($stmt) {
            $stmt->bind_param("ii", $projection_id, $projection_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    $values[] = $projection_id;
    $types .= 'i';

    $sql = "UPDATE delivery_projections SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$values);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Delete a projection and all related data.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return bool Success
 */
function delete_projection($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return false;
    }

    // Foreign keys with ON DELETE CASCADE will handle related records
    $stmt = $conn->prepare("DELETE FROM delivery_projections WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $projection_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * MODULE ALLOCATION FUNCTIONS
 * =====================================================
 */

/**
 * Get module allocations for a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array Module allocations with batch info
 */
function get_projection_module_allocations($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return [];
    }

    // Load allocations from real modules
    $stmt = $conn->prepare("
        SELECT pma.id, pma.projection_id, pma.module_id, pma.wattage, pma.quantity, pma.pallets,
               pma.po_execution_date, pma.is_projection_module,
               m.vendor_name,
               m.vendor_name AS manufacturer_name,
               m.initial_location AS manufacturer_address,
               m.cost_per_watt,
               m.modules_per_pallet,
               m.pallets_per_truck
        FROM projection_module_allocations pma
        JOIN modules m ON m.id = pma.module_id
        WHERE pma.projection_id = ? AND pma.is_projection_module = 0
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $allocations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Load allocations from projection-only modules
    $proj_stmt = $conn->prepare("
        SELECT pma.id, pma.projection_id, pma.module_id, pma.wattage, pma.quantity, pma.pallets,
               pma.po_execution_date, pma.is_projection_module,
               pm.vendor_name,
               pm.vendor_name AS manufacturer_name,
               pm.initial_location AS manufacturer_address,
               pm.cost_per_watt,
               pm.modules_per_pallet,
               pm.pallets_per_truck
        FROM projection_module_allocations pma
        JOIN projection_modules pm ON pm.id = pma.module_id
        WHERE pma.projection_id = ? AND pma.is_projection_module = 1
    ");

    if ($proj_stmt) {
        $proj_stmt->bind_param("i", $projection_id);
        $proj_stmt->execute();
        $proj_allocations = $proj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $proj_stmt->close();
        $allocations = array_merge($allocations, $proj_allocations);
    }

    // Calculate contract value and milestone info for each allocation
    foreach ($allocations as &$alloc) {
        $total_watts = $alloc['wattage'] * $alloc['quantity'];
        $alloc['contract_value'] = $alloc['cost_per_watt']
            ? round($alloc['cost_per_watt'] * $total_watts, 2)
            : 0;

        // Get milestone configuration from the appropriate table
        if (!empty($alloc['is_projection_module'])) {
            $alloc['milestones'] = get_projection_module_milestones($alloc['module_id'], $conn);
        } else {
            $alloc['milestones'] = get_module_milestones($alloc['module_id'], $conn);
        }
        $alloc['has_milestones'] = !empty($alloc['milestones']);

        // Calculate pallets if not set
        if (!$alloc['pallets'] && $alloc['modules_per_pallet']) {
            $alloc['pallets'] = ceil($alloc['quantity'] / $alloc['modules_per_pallet']);
        }
    }

    return $allocations;
}

/**
 * Add module allocation to a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param int $module_id Module batch ID (from modules or projection_modules table)
 * @param int $wattage Wattage
 * @param int $quantity Quantity
 * @param int|null $pallets Override pallet count
 * @param bool $is_projection_module Whether module_id refers to projection_modules table
 * @return array ['success' => bool, 'allocation_id' => int|null, 'error' => string|null]
 */
function add_module_allocation($conn, $projection_id, $module_id, $wattage, $quantity, $pallets = null, $is_projection_module = false) {
    $result = ['success' => false, 'allocation_id' => null, 'error' => null];

    if (!$conn || !$projection_id || !$module_id || !$wattage || !$quantity) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    $is_proj = $is_projection_module ? 1 : 0;
    $stmt = $conn->prepare("
        INSERT INTO projection_module_allocations (projection_id, module_id, wattage, quantity, pallets, is_projection_module)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stmt->bind_param("iiiiii", $projection_id, $module_id, $wattage, $quantity, $pallets, $is_proj);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['allocation_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to add allocation';
    }

    $stmt->close();
    return $result;
}

/**
 * Remove module allocation from a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $allocation_id Allocation ID
 * @return bool Success
 */
function remove_module_allocation($conn, $allocation_id) {
    if (!$conn || !$allocation_id) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM projection_module_allocations WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $allocation_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * STOP FUNCTIONS
 * =====================================================
 */

/**
 * Get all stops for a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array Stops with fees
 */
function get_projection_stops($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT ps.*,
               w.name AS warehouse_name
        FROM projection_stops ps
        LEFT JOIN warehouses w ON w.id = ps.warehouse_id
        WHERE ps.projection_id = ?
        ORDER BY ps.stop_order ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $stops = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get fees for each stop
    foreach ($stops as &$stop) {
        $stop['fees'] = get_stop_fees($conn, $stop['id']);
        $stop['total_fees'] = array_sum(array_column($stop['fees'], 'estimated_cost'));
    }

    return $stops;
}

/**
 * Add a stop to a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param array $stop_data Stop data
 * @return array ['success' => bool, 'stop_id' => int|null, 'error' => string|null]
 */
function add_projection_stop($conn, $projection_id, $stop_data) {
    $result = ['success' => false, 'stop_id' => null, 'error' => null];

    if (!$conn || !$projection_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // Get next stop order
    $stmt = $conn->prepare("SELECT MAX(stop_order) as max_order FROM projection_stops WHERE projection_id = ?");
    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $max = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stop_order = ($max['max_order'] ?? -1) + 1;

    $stmt = $conn->prepare("
        INSERT INTO projection_stops
        (projection_id, stop_order, stop_type, location_name, location_address,
         latitude, longitude, warehouse_id, is_customs_clearance,
         estimated_arrival_date, estimated_departure_date, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $stop_type = $stop_data['stop_type'] ?? 'warehouse';
    $location_name = $stop_data['location_name'] ?? '';
    $location_address = $stop_data['location_address'] ?? null;
    $latitude = $stop_data['latitude'] ?? null;
    $longitude = $stop_data['longitude'] ?? null;
    $warehouse_id = $stop_data['warehouse_id'] ?? null;
    $is_customs = $stop_data['is_customs_clearance'] ?? 0;
    $arrival = $stop_data['estimated_arrival_date'] ?? null;
    $departure = $stop_data['estimated_departure_date'] ?? null;
    $notes = $stop_data['notes'] ?? null;

    $stmt->bind_param(
        "iisssddiiiss",
        $projection_id, $stop_order, $stop_type, $location_name, $location_address,
        $latitude, $longitude, $warehouse_id, $is_customs,
        $arrival, $departure, $notes
    );

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['stop_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to add stop';
    }

    $stmt->close();
    return $result;
}

/**
 * Update a stop.
 *
 * @param mysqli $conn Database connection
 * @param int $stop_id Stop ID
 * @param array $stop_data Stop data to update
 * @return bool Success
 */
function update_projection_stop($conn, $stop_id, $stop_data) {
    if (!$conn || !$stop_id || empty($stop_data)) {
        return false;
    }

    $allowed_fields = [
        'stop_order', 'stop_type', 'location_name', 'location_address',
        'latitude', 'longitude', 'warehouse_id', 'is_customs_clearance',
        'estimated_arrival_date', 'estimated_departure_date', 'notes'
    ];

    $updates = [];
    $types = '';
    $values = [];

    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $stop_data)) {
            $updates[] = "`$field` = ?";
            if (in_array($field, ['stop_order', 'warehouse_id', 'is_customs_clearance'])) {
                $types .= 'i';
            } elseif (in_array($field, ['latitude', 'longitude'])) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $stop_data[$field];
        }
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $stop_id;
    $types .= 'i';

    $sql = "UPDATE projection_stops SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$values);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Delete a stop and its fees.
 *
 * @param mysqli $conn Database connection
 * @param int $stop_id Stop ID
 * @return bool Success
 */
function delete_projection_stop($conn, $stop_id) {
    if (!$conn || !$stop_id) {
        return false;
    }

    // Get projection ID for reordering
    $stmt = $conn->prepare("SELECT projection_id, stop_order FROM projection_stops WHERE id = ?");
    $stmt->bind_param("i", $stop_id);
    $stmt->execute();
    $stop_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$stop_info) {
        return false;
    }

    // Delete stop (fees will cascade)
    $stmt = $conn->prepare("DELETE FROM projection_stops WHERE id = ?");
    $stmt->bind_param("i", $stop_id);
    $success = $stmt->execute();
    $stmt->close();

    if ($success) {
        // Reorder remaining stops
        $stmt = $conn->prepare("
            UPDATE projection_stops
            SET stop_order = stop_order - 1
            WHERE projection_id = ? AND stop_order > ?
        ");
        $stmt->bind_param("ii", $stop_info['projection_id'], $stop_info['stop_order']);
        $stmt->execute();
        $stmt->close();
    }

    return $success;
}

/**
 * =====================================================
 * STOP FEE FUNCTIONS
 * =====================================================
 */

/**
 * Get fees for a stop.
 *
 * @param mysqli $conn Database connection
 * @param int $stop_id Stop ID
 * @return array Fees
 */
function get_stop_fees($conn, $stop_id) {
    if (!$conn || !$stop_id) {
        return [];
    }

    $stmt = $conn->prepare("SELECT * FROM projection_stop_fees WHERE stop_id = ? ORDER BY fee_type, id");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $stop_id);
    $stmt->execute();
    $fees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $fees;
}

/**
 * Add a fee to a stop.
 *
 * @param mysqli $conn Database connection
 * @param int $stop_id Stop ID
 * @param array $fee_data Fee data
 * @return array ['success' => bool, 'fee_id' => int|null, 'error' => string|null]
 */
function add_stop_fee($conn, $stop_id, $fee_data) {
    $result = ['success' => false, 'fee_id' => null, 'error' => null];

    if (!$conn || !$stop_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    $stmt = $conn->prepare("
        INSERT INTO projection_stop_fees
        (stop_id, fee_type, fee_name, rate, rate_unit, quantity, duration_days, estimated_cost)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $fee_type = $fee_data['fee_type'] ?? 'other';
    $fee_name = $fee_data['fee_name'] ?? '';
    $rate = $fee_data['rate'] ?? 0;
    $rate_unit = $fee_data['rate_unit'] ?? 'per_pallet';
    $quantity = $fee_data['quantity'] ?? null;
    $duration = $fee_data['duration_days'] ?? null;
    $estimated_cost = $fee_data['estimated_cost'] ?? 0;

    $stmt->bind_param(
        "issdsiid",
        $stop_id, $fee_type, $fee_name, $rate, $rate_unit, $quantity, $duration, $estimated_cost
    );

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['fee_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to add fee';
    }

    $stmt->close();
    return $result;
}

/**
 * Update a stop fee.
 *
 * @param mysqli $conn Database connection
 * @param int $fee_id Fee ID
 * @param array $fee_data Fee data
 * @return bool Success
 */
function update_stop_fee($conn, $fee_id, $fee_data) {
    if (!$conn || !$fee_id || empty($fee_data)) {
        return false;
    }

    $allowed_fields = ['fee_type', 'fee_name', 'rate', 'rate_unit', 'quantity', 'duration_days', 'estimated_cost'];
    $updates = [];
    $types = '';
    $values = [];

    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $fee_data)) {
            $updates[] = "`$field` = ?";
            if (in_array($field, ['quantity', 'duration_days'])) {
                $types .= 'i';
            } elseif (in_array($field, ['rate', 'estimated_cost'])) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $fee_data[$field];
        }
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $fee_id;
    $types .= 'i';

    $sql = "UPDATE projection_stop_fees SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$values);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Delete a stop fee.
 *
 * @param mysqli $conn Database connection
 * @param int $fee_id Fee ID
 * @return bool Success
 */
function delete_stop_fee($conn, $fee_id) {
    if (!$conn || !$fee_id) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM projection_stop_fees WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $fee_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * LEG FUNCTIONS
 * =====================================================
 */

/**
 * Get all legs for a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array Legs with stop info
 */
function get_projection_legs($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT pl.*,
               fs.location_name AS from_location,
               fs.stop_type AS from_type,
               ts.location_name AS to_location,
               ts.stop_type AS to_type
        FROM projection_legs pl
        JOIN projection_stops fs ON fs.id = pl.from_stop_id
        JOIN projection_stops ts ON ts.id = pl.to_stop_id
        WHERE pl.projection_id = ?
        ORDER BY pl.leg_order ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $legs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $legs;
}

/**
 * Add a leg to a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param array $leg_data Leg data
 * @return array ['success' => bool, 'leg_id' => int|null, 'error' => string|null]
 */
function add_projection_leg($conn, $projection_id, $leg_data) {
    $result = ['success' => false, 'leg_id' => null, 'error' => null];

    if (!$conn || !$projection_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // Get next leg order
    $stmt = $conn->prepare("SELECT MAX(leg_order) as max_order FROM projection_legs WHERE projection_id = ?");
    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $max = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $leg_order = ($max['max_order'] ?? -1) + 1;

    $stmt = $conn->prepare("
        INSERT INTO projection_legs
        (projection_id, from_stop_id, to_stop_id, leg_order, transport_mode,
         start_date, end_date, delivery_rate, delivery_rate_unit,
         trucks_required, freight_cost_per_truck, accessorial_cost_per_truck,
         total_freight_cost, triggers_milestone, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error';
        return $result;
    }

    $from_stop = $leg_data['from_stop_id'];
    $to_stop = $leg_data['to_stop_id'];
    $transport = $leg_data['transport_mode'] ?? 'truck';
    $start = $leg_data['start_date'] ?? null;
    $end = $leg_data['end_date'] ?? null;
    $rate = $leg_data['delivery_rate'] ?? null;
    $rate_unit = $leg_data['delivery_rate_unit'] ?? 'per_week';
    $trucks = $leg_data['trucks_required'] ?? null;
    $freight = $leg_data['freight_cost_per_truck'] ?? null;
    $accessorial = $leg_data['accessorial_cost_per_truck'] ?? null;
    $total = $leg_data['total_freight_cost'] ?? null;
    $milestone = $leg_data['triggers_milestone'] ?? null;
    $notes = $leg_data['notes'] ?? null;

    $stmt->bind_param(
        "iiiisssdsidddss",
        $projection_id, $from_stop, $to_stop, $leg_order, $transport,
        $start, $end, $rate, $rate_unit,
        $trucks, $freight, $accessorial,
        $total, $milestone, $notes
    );

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['leg_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to add leg';
    }

    $stmt->close();
    return $result;
}

/**
 * Update a leg.
 *
 * @param mysqli $conn Database connection
 * @param int $leg_id Leg ID
 * @param array $leg_data Leg data
 * @return bool Success
 */
function update_projection_leg($conn, $leg_id, $leg_data) {
    if (!$conn || !$leg_id || empty($leg_data)) {
        return false;
    }

    $allowed_fields = [
        'from_stop_id', 'to_stop_id', 'leg_order', 'transport_mode',
        'start_date', 'end_date', 'delivery_rate', 'delivery_rate_unit',
        'trucks_required', 'freight_cost_per_truck', 'accessorial_cost_per_truck',
        'total_freight_cost', 'triggers_milestone', 'notes'
    ];

    $updates = [];
    $types = '';
    $values = [];

    foreach ($allowed_fields as $field) {
        if (array_key_exists($field, $leg_data)) {
            $updates[] = "`$field` = ?";
            if (in_array($field, ['from_stop_id', 'to_stop_id', 'leg_order', 'trucks_required'])) {
                $types .= 'i';
            } elseif (in_array($field, ['delivery_rate', 'freight_cost_per_truck', 'accessorial_cost_per_truck', 'total_freight_cost'])) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $leg_data[$field];
        }
    }

    if (empty($updates)) {
        return false;
    }

    $values[] = $leg_id;
    $types .= 'i';

    $sql = "UPDATE projection_legs SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param($types, ...$values);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * Delete a leg.
 *
 * @param mysqli $conn Database connection
 * @param int $leg_id Leg ID
 * @return bool Success
 */
function delete_projection_leg($conn, $leg_id) {
    if (!$conn || !$leg_id) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM projection_legs WHERE id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $leg_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * COST CALCULATION FUNCTIONS
 * =====================================================
 */

/**
 * Get or create cost summary for a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array|null Cost summary
 */
function get_projection_cost_summary($conn, $projection_id) {
    if (!$conn || !$projection_id) {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM projection_cost_summary WHERE projection_id = ?");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $projection_id);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $summary;
}

/**
 * Calculate and update cost summary for a projection.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @return array Updated cost summary
 */
function calculate_projection_costs($conn, $projection_id) {
    $summary = [
        'projection_id' => $projection_id,
        'module_contract_value' => 0,
        'total_milestone_payments' => 0,
        'total_freight_cost' => 0,
        'total_warehousing_cost' => 0,
        'total_customs_cost' => 0,
        'total_other_cost' => 0,
        'grand_total' => 0
    ];

    if (!$conn || !$projection_id) {
        return $summary;
    }

    // Calculate module contract value
    $allocations = get_projection_module_allocations($conn, $projection_id);
    foreach ($allocations as $alloc) {
        $summary['module_contract_value'] += $alloc['contract_value'] ?? 0;
    }
    $summary['total_milestone_payments'] = $summary['module_contract_value']; // Same as contract value

    // Calculate freight costs from legs
    $legs = get_projection_legs($conn, $projection_id);
    foreach ($legs as $leg) {
        $summary['total_freight_cost'] += floatval($leg['total_freight_cost'] ?? 0);
    }

    // Calculate stop fees
    $stops = get_projection_stops($conn, $projection_id);
    foreach ($stops as $stop) {
        foreach ($stop['fees'] as $fee) {
            $cost = floatval($fee['estimated_cost'] ?? 0);
            switch ($fee['fee_type']) {
                case 'storage':
                case 'receiving':
                case 'outbound':
                    $summary['total_warehousing_cost'] += $cost;
                    break;
                case 'customs':
                    $summary['total_customs_cost'] += $cost;
                    break;
                default:
                    $summary['total_other_cost'] += $cost;
            }
        }
    }

    // Calculate grand total
    $summary['grand_total'] = $summary['module_contract_value']
        + $summary['total_freight_cost']
        + $summary['total_warehousing_cost']
        + $summary['total_customs_cost']
        + $summary['total_other_cost'];

    // Save to database
    save_projection_cost_summary($conn, $summary);

    return $summary;
}

/**
 * Save cost summary to database.
 *
 * @param mysqli $conn Database connection
 * @param array $summary Cost summary data
 * @return bool Success
 */
function save_projection_cost_summary($conn, $summary) {
    if (!$conn || empty($summary['projection_id'])) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO projection_cost_summary
        (projection_id, module_contract_value, total_milestone_payments,
         total_freight_cost, total_warehousing_cost, total_customs_cost,
         total_other_cost, grand_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        module_contract_value = VALUES(module_contract_value),
        total_milestone_payments = VALUES(total_milestone_payments),
        total_freight_cost = VALUES(total_freight_cost),
        total_warehousing_cost = VALUES(total_warehousing_cost),
        total_customs_cost = VALUES(total_customs_cost),
        total_other_cost = VALUES(total_other_cost),
        grand_total = VALUES(grand_total)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iddddddd",
        $summary['projection_id'],
        $summary['module_contract_value'],
        $summary['total_milestone_payments'],
        $summary['total_freight_cost'],
        $summary['total_warehousing_cost'],
        $summary['total_customs_cost'],
        $summary['total_other_cost'],
        $summary['grand_total']
    );

    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * TEMPLATE FUNCTIONS
 * =====================================================
 */

/**
 * Get all available templates.
 *
 * @param mysqli $conn Database connection
 * @param int|null $account_id Optional account filter
 * @return array Templates
 */
function get_projection_templates($conn, $account_id = null) {
    if (!$conn) {
        return [];
    }

    $sql = "
        SELECT dp.*,
               p.account_id,
               u.username AS created_by_name,
               pcs.grand_total
        FROM delivery_projections dp
        JOIN projects p ON p.id = dp.project_id
        LEFT JOIN users u ON u.id = dp.created_by
        LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id
        WHERE dp.is_template = 1
    ";

    if ($account_id) {
        $sql .= " AND p.account_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $account_id);
    } else {
        $stmt = $conn->prepare($sql);
    }

    if (!$stmt) {
        return [];
    }

    $stmt->execute();
    $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $templates;
}

/**
 * Save a projection as a template.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param string $template_name Template name
 * @return bool Success
 */
function save_as_template($conn, $projection_id, $template_name) {
    if (!$conn || !$projection_id || !$template_name) {
        return false;
    }

    $stmt = $conn->prepare("
        UPDATE delivery_projections
        SET is_template = 1, template_name = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("si", $template_name, $projection_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

/**
 * =====================================================
 * UTILITY FUNCTIONS
 * =====================================================
 */

/**
 * Check if a project has any projections.
 *
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return bool Has projections
 */
function project_has_projection($conn, $project_id) {
    if (!$conn || !$project_id) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1 FROM delivery_projections
        WHERE project_id = ? AND is_template = 0
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result !== null;
}

/**
 * Get available module batches for a project that can be allocated.
 *
 * @param mysqli $conn Database connection
 * @param int $project_id Project ID
 * @return array Module batches
 */
function get_available_module_batches($conn, $project_id) {
    if (!$conn || !$project_id) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT m.id, m.vendor_name, m.cost_per_watt, m.modules_per_pallet, m.pallets_per_truck,
               m.initial_location,
               GROUP_CONCAT(DISTINCT umi.wattage) AS wattages,
               SUM(umi.quantity) AS total_quantity
        FROM modules m
        JOIN unassigned_module_items umi ON umi.unassigned_module_id = m.id
        WHERE m.project_id = ?
          AND (m.module_notes IS NULL OR m.module_notes != 'Created via projection manual entry')
        GROUP BY m.id
        ORDER BY m.vendor_name
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Parse wattages and add milestone info
    foreach ($batches as &$batch) {
        $batch['wattage_list'] = array_map('intval', explode(',', $batch['wattages'] ?? ''));
        $batch['milestones'] = get_module_milestones($batch['id'], $conn);
        $batch['has_milestones'] = !empty($batch['milestones']);
        // Use vendor_name as manufacturer_name for display
        $batch['manufacturer_name'] = $batch['vendor_name'] ?? 'Unknown';
        $batch['manufacturer_address'] = $batch['initial_location'] ?? '';
    }

    return $batches;
}

/**
 * Create origin stop from module batch manufacturer.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param array $manufacturer Manufacturer data
 * @return array Result
 */
function create_origin_from_manufacturer($conn, $projection_id, $manufacturer) {
    $location_parts = array_filter([
        $manufacturer['manufacturer_city'] ?? '',
        $manufacturer['manufacturer_country'] ?? ''
    ]);

    $location_name = $manufacturer['manufacturer_name'] ?? 'Manufacturer';
    $location_address = $manufacturer['manufacturer_address'] ?? implode(', ', $location_parts);

    return add_projection_stop($conn, $projection_id, [
        'stop_type' => 'origin',
        'location_name' => $location_name,
        'location_address' => $location_address
    ]);
}

/**
 * Create destination stop from project.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param int $project_id Project ID
 * @return array Result
 */
function create_destination_from_project($conn, $projection_id, $project_id) {
    $stmt = $conn->prepare("SELECT project_name, project_address FROM projects WHERE id = ?");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error'];
    }

    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$project) {
        return ['success' => false, 'error' => 'Project not found'];
    }

    return add_projection_stop($conn, $projection_id, [
        'stop_type' => 'destination',
        'location_name' => $project['project_name'],
        'location_address' => $project['project_address'],
        'latitude' => null,
        'longitude' => null
    ]);
}

/**
 * Initialize a new projection with origin and destination stops.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param int $project_id Project ID
 * @param array|null $manufacturer Manufacturer data for origin
 * @return bool Success
 */
function initialize_projection_stops($conn, $projection_id, $project_id, $manufacturer = null) {
    // Create origin if manufacturer provided
    if ($manufacturer) {
        $origin_result = create_origin_from_manufacturer($conn, $projection_id, $manufacturer);
        if (!$origin_result['success']) {
            return false;
        }
    }

    // Create destination from project
    $dest_result = create_destination_from_project($conn, $projection_id, $project_id);

    return $dest_result['success'];
}

/**
 * Calculate estimated fee based on rate and units.
 *
 * @param float $rate Rate amount
 * @param string $rate_unit Unit type
 * @param int $quantity Quantity (pallets, modules, trucks)
 * @param int|null $duration_days Duration for storage fees
 * @return float Estimated cost
 */
function calculate_fee_cost($rate, $rate_unit, $quantity, $duration_days = null) {
    switch ($rate_unit) {
        case 'flat':
            return $rate;
        case 'per_pallet':
        case 'per_module':
        case 'per_truck':
            return $rate * $quantity;
        case 'per_day':
            $days = $duration_days ?? 1;
            return $rate * $quantity * $days;
        default:
            return $rate * $quantity;
    }
}

/**
 * =====================================================
 * GENERAL PROJECTION FUNCTIONS
 * =====================================================
 */

/**
 * Create a new general projection (not tied to a project).
 *
 * @param mysqli $conn Database connection
 * @param string $projection_name Name for the projection
 * @param int $user_id User creating the projection
 * @param array $general_data General projection data (general_project_name, general_project_address, general_estimated_mw)
 * @return array ['success' => bool, 'projection_id' => int|null, 'error' => string|null]
 */
function create_general_projection($conn, $projection_name, $user_id, $general_data) {
    $result = ['success' => false, 'projection_id' => null, 'error' => null];

    if (!$conn || !$user_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    $general_project_name = $general_data['general_project_name'] ?? $projection_name;
    $general_project_address = $general_data['general_project_address'] ?? '';
    $general_estimated_mw = isset($general_data['general_estimated_mw']) ? floatval($general_data['general_estimated_mw']) : null;

    $stmt = $conn->prepare("
        INSERT INTO delivery_projections
        (project_id, projection_name, is_primary, is_general, general_project_name, general_project_address, general_estimated_mw, created_by)
        VALUES (NULL, ?, 0, 1, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        $result['error'] = 'Database error: ' . $conn->error;
        return $result;
    }

    $stmt->bind_param("sssdi", $projection_name, $general_project_name, $general_project_address, $general_estimated_mw, $user_id);

    if ($stmt->execute()) {
        $result['success'] = true;
        $result['projection_id'] = $conn->insert_id;
    } else {
        $result['error'] = 'Failed to create general projection: ' . $stmt->error;
    }

    $stmt->close();
    return $result;
}

/**
 * Get all general projections accessible by a user.
 *
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @param string $role User role
 * @return array List of general projections
 */
function get_general_projections($conn, $user_id, $role) {
    if (!$conn) {
        return [];
    }

    if ($role === 'global_admin') {
        $stmt = $conn->prepare("
            SELECT dp.*,
                   u.username AS created_by_name,
                   pcs.grand_total
            FROM delivery_projections dp
            LEFT JOIN users u ON u.id = dp.created_by
            LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id
            WHERE dp.is_general = 1 AND dp.is_template = 0
            ORDER BY dp.created_at DESC
        ");
        if (!$stmt) return [];
        $stmt->execute();
    } else {
        // Get projections created by users in the same accounts
        $stmt = $conn->prepare("
            SELECT dp.*,
                   u.username AS created_by_name,
                   pcs.grand_total
            FROM delivery_projections dp
            LEFT JOIN users u ON u.id = dp.created_by
            LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id
            WHERE dp.is_general = 1 AND dp.is_template = 0
              AND dp.created_by IN (
                  SELECT cau2.user_id FROM customer_account_users cau
                  JOIN customer_account_users cau2 ON cau2.account_id = cau.account_id
                  WHERE cau.user_id = ?
              )
            ORDER BY dp.created_at DESC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }

    $projections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $projections;
}

/**
 * Link a general projection to a real project.
 *
 * @param mysqli $conn Database connection
 * @param int $projection_id Projection ID
 * @param int $project_id Project ID to link to
 * @return array ['success' => bool, 'error' => string|null]
 */
function link_general_to_project($conn, $projection_id, $project_id) {
    $result = ['success' => false, 'error' => null];

    if (!$conn || !$projection_id || !$project_id) {
        $result['error'] = 'Invalid parameters';
        return $result;
    }

    // Verify projection exists and is general
    $check = $conn->prepare("SELECT id FROM delivery_projections WHERE id = ? AND is_general = 1");
    if (!$check) {
        $result['error'] = 'Database error';
        return $result;
    }
    $check->bind_param("i", $projection_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        $result['error'] = 'Projection not found or is not a general projection';
        return $result;
    }
    $check->close();

    // Update the projection to link it
    $stmt = $conn->prepare("
        UPDATE delivery_projections
        SET linked_project_id = ?, project_id = ?
        WHERE id = ? AND is_general = 1
    ");
    if (!$stmt) {
        $result['error'] = 'Database error: ' . $conn->error;
        return $result;
    }

    $stmt->bind_param("iii", $project_id, $project_id, $projection_id);
    if (!$stmt->execute()) {
        $result['error'] = 'Failed to link projection: ' . $stmt->error;
        $stmt->close();
        return $result;
    }
    $stmt->close();

    // Update destination stop to use real project address
    $proj_stmt = $conn->prepare("SELECT project_name, project_address FROM projects WHERE id = ?");
    if ($proj_stmt) {
        $proj_stmt->bind_param("i", $project_id);
        $proj_stmt->execute();
        $project = $proj_stmt->get_result()->fetch_assoc();
        $proj_stmt->close();

        if ($project) {
            $update_stop = $conn->prepare("
                UPDATE projection_stops
                SET location_name = ?, location_address = ?
                WHERE projection_id = ? AND stop_type = 'destination'
            ");
            if ($update_stop) {
                $update_stop->bind_param("ssi", $project['project_name'], $project['project_address'], $projection_id);
                $update_stop->execute();
                $update_stop->close();
            }
        }
    }

    $result['success'] = true;
    return $result;
}
