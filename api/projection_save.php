<?php
/**
 * API: Save Delivery Projection
 * Creates or updates a projection with all related data
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Check permission
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit();
}

require_once '../../config.php';
require_once '../projection_helpers.php';
require_once '../milestone_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

try {
    $conn->begin_transaction();

    $project_id = intval($input['project_id'] ?? 0);
    $projection_id = intval($input['projection_id'] ?? 0);

    if (!$project_id) {
        throw new Exception('Project ID is required');
    }

    // Verify user has access to project
    $access_check = $conn->prepare("
        SELECT p.id FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
    ");
    $access_check->bind_param("ii", $project_id, $user_id);
    $access_check->execute();
    if ($access_check->get_result()->num_rows === 0 && $role !== 'global_admin') {
        throw new Exception('Access denied to this project');
    }
    $access_check->close();

    // Create or update projection
    if ($projection_id) {
        // Update existing
        $update_result = update_projection($conn, $projection_id, [
            'projection_name' => $input['projection_name'] ?? 'Default Projection',
            'status' => $input['status'] ?? 'draft',
            'notes' => $input['notes'] ?? null,
            'is_primary' => $input['is_primary'] ?? false
        ]);

        if (!$update_result) {
            throw new Exception('Failed to update projection');
        }
    } else {
        // Create new
        $result = create_projection(
            $conn,
            $project_id,
            $input['projection_name'] ?? 'Default Projection',
            $user_id,
            $input['is_primary'] ?? true // First projection is primary by default
        );

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Failed to create projection');
        }

        $projection_id = $result['projection_id'];

        // Initialize with origin and destination if module data provided
        if (!empty($input['module_allocations'])) {
            $first_alloc = $input['module_allocations'][0];
            // Get manufacturer info
            $mfr_stmt = $conn->prepare("
                SELECT m.id, m.vendor_name AS manufacturer_name, m.initial_location AS manufacturer_address
                FROM modules m
                WHERE m.id = ?
            ");
            $mfr_stmt->bind_param("i", $first_alloc['module_id']);
            $mfr_stmt->execute();
            $mfr_data = $mfr_stmt->get_result()->fetch_assoc();
            $mfr_stmt->close();
            // Set defaults for missing fields
            if ($mfr_data) {
                $mfr_data['manufacturer_city'] = '';
                $mfr_data['manufacturer_country'] = '';
            }

            if ($mfr_data) {
                initialize_projection_stops($conn, $projection_id, $project_id, $mfr_data);
            }
        } else {
            // Just create destination from project
            create_destination_from_project($conn, $projection_id, $project_id);
        }
    }

    // Handle module allocations
    if (isset($input['module_allocations'])) {
        // Clear existing allocations
        $clear_stmt = $conn->prepare("DELETE FROM projection_module_allocations WHERE projection_id = ?");
        $clear_stmt->bind_param("i", $projection_id);
        $clear_stmt->execute();
        $clear_stmt->close();

        // Get project's account_id for manual entries
        $account_id = 0;
        $acc_stmt = $conn->prepare("SELECT account_id FROM projects WHERE id = ?");
        $acc_stmt->bind_param("i", $project_id);
        $acc_stmt->execute();
        $acc_result = $acc_stmt->get_result()->fetch_assoc();
        if ($acc_result) {
            $account_id = $acc_result['account_id'];
        }
        $acc_stmt->close();

        // Add new allocations
        foreach ($input['module_allocations'] as $alloc) {
            $module_id = $alloc['module_id'];
            $po_execution_date = null;
            $po_execution_raw = $alloc['po_execution_date'] ?? null;
            if (is_string($po_execution_raw) && $po_execution_raw !== '') {
                $date = DateTime::createFromFormat('Y-m-d', $po_execution_raw);
                if ($date) {
                    $po_execution_date = $date->format('Y-m-d');
                }
            }

            // Check if this is a manual entry (module_id starts with 'manual_' or is not numeric)
            $module_id_str = strval($module_id);
            if (!is_numeric($module_id) || strpos($module_id_str, 'manual_') === 0) {
                // Create a lightweight module record for this manual entry
                $vendor_name = $alloc['vendor_name'] ?? 'Manual Entry';
                $location = $alloc['manufacturer_address'] ?? '';
                $mods_per_pallet = intval($alloc['modules_per_pallet'] ?? 30);
                $pallets_per_truck = intval($alloc['pallets_per_truck'] ?? 20);
                $cost_per_watt = floatval($alloc['cost_per_watt'] ?? 0);

                $insert_stmt = $conn->prepare("
                    INSERT INTO modules (account_id, vendor_name, initial_location, project_id,
                                        modules_per_pallet, pallets_per_truck, cost_per_watt, module_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Created via projection manual entry')
                ");
                if (!$insert_stmt) {
                    throw new Exception('Failed to prepare manual module insert: ' . $conn->error);
                }
                $insert_stmt->bind_param("issiiid", $account_id, $vendor_name, $location, $project_id,
                                         $mods_per_pallet, $pallets_per_truck, $cost_per_watt);
                $insert_stmt->execute();
                $module_id = $conn->insert_id;
                $insert_stmt->close();

                // Create the unassigned_module_items entry
                $wattage = intval($alloc['wattage']);
                $quantity = intval($alloc['quantity']);
                $items_stmt = $conn->prepare("
                    INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity)
                    VALUES (?, ?, ?)
                ");
                if (!$items_stmt) {
                    throw new Exception('Failed to prepare manual module items insert: ' . $conn->error);
                }
                $items_stmt->bind_param("iii", $module_id, $wattage, $quantity);
                $items_stmt->execute();
                $items_stmt->close();

                if (!empty($alloc['milestones']) && is_array($alloc['milestones'])) {
                    $saved = save_module_milestones($module_id, $alloc['milestones'], $conn, $user_id);
                    if (!$saved) {
                        throw new Exception('Failed to save manual milestones');
                    }
                }
            }

            $allocation_result = add_module_allocation(
                $conn,
                $projection_id,
                intval($module_id),
                intval($alloc['wattage']),
                intval($alloc['quantity']),
                isset($alloc['pallets']) ? intval($alloc['pallets']) : null
            );

            if (!$allocation_result['success']) {
                throw new Exception($allocation_result['error'] ?? 'Failed to add module allocation');
            }

            if ($po_execution_date) {
                $po_stmt = $conn->prepare("UPDATE projection_module_allocations SET po_execution_date = ? WHERE id = ?");
                if ($po_stmt) {
                    $po_stmt->bind_param("si", $po_execution_date, $allocation_result['allocation_id']);
                    $po_stmt->execute();
                    $po_stmt->close();
                }
            }
        }
    }

    // Check if we need to auto-create origin from modules
    $has_origin_in_input = false;
    if (isset($input['stops'])) {
        foreach ($input['stops'] as $stop) {
            if (($stop['stop_type'] ?? '') === 'origin') {
                $has_origin_in_input = true;
                break;
            }
        }
    }

    // If no origin in input but we have module allocations, create origin from first module's manufacturer
    if (!$has_origin_in_input && !empty($input['module_allocations'])) {
        $first_alloc = $input['module_allocations'][0];
        $mfr_stmt = $conn->prepare("
            SELECT m.id, m.vendor_name AS manufacturer_name, m.initial_location AS manufacturer_address
            FROM modules m
            WHERE m.id = ?
        ");
        $mfr_stmt->bind_param("i", $first_alloc['module_id']);
        $mfr_stmt->execute();
        $mfr_data = $mfr_stmt->get_result()->fetch_assoc();
        $mfr_stmt->close();

        if ($mfr_data) {
            // Add origin stop to input
            if (!isset($input['stops'])) {
                $input['stops'] = [];
            }
            // Check if destination exists
            $has_destination = false;
            foreach ($input['stops'] as $stop) {
                if (($stop['stop_type'] ?? '') === 'destination') {
                    $has_destination = true;
                    break;
                }
            }

            // Add origin at the beginning
            array_unshift($input['stops'], [
                'id' => 'auto_origin',
                'stop_type' => 'origin',
                'location_name' => $mfr_data['manufacturer_name'] ?? 'Manufacturer',
                'location_address' => $mfr_data['manufacturer_address'] ?? '',
                'latitude' => null,
                'longitude' => null
            ]);

            // If no destination, create from project
            if (!$has_destination) {
                $proj_stmt = $conn->prepare("SELECT project_name, project_address FROM projects WHERE id = ?");
                $proj_stmt->bind_param("i", $project_id);
                $proj_stmt->execute();
                $proj_data = $proj_stmt->get_result()->fetch_assoc();
                $proj_stmt->close();

                if ($proj_data) {
                    $input['stops'][] = [
                        'id' => 'auto_destination',
                        'stop_type' => 'destination',
                        'location_name' => $proj_data['project_name'],
                        'location_address' => $proj_data['project_address'],
                        'latitude' => null,
                        'longitude' => null
                    ];
                }
            }
        }
    }

    // Handle stops
    if (isset($input['stops'])) {
        // Clear existing stops (cascades to fees and legs)
        $clear_stmt = $conn->prepare("DELETE FROM projection_stops WHERE projection_id = ?");
        $clear_stmt->bind_param("i", $projection_id);
        $clear_stmt->execute();
        $clear_stmt->close();

        // Also clear legs
        $clear_legs = $conn->prepare("DELETE FROM projection_legs WHERE projection_id = ?");
        $clear_legs->bind_param("i", $projection_id);
        $clear_legs->execute();
        $clear_legs->close();

        $stop_id_map = []; // Map old IDs to new IDs

        // Add stops
        foreach ($input['stops'] as $index => $stop) {
            $old_id = $stop['id'] ?? 'temp_' . $index;

            $stop_result = add_projection_stop($conn, $projection_id, [
                'stop_type' => $stop['stop_type'] ?? 'warehouse',
                'location_name' => $stop['location_name'] ?? '',
                'location_address' => $stop['location_address'] ?? null,
                'latitude' => $stop['latitude'] ?? null,
                'longitude' => $stop['longitude'] ?? null,
                'warehouse_id' => $stop['warehouse_id'] ?? null,
                'is_customs_clearance' => $stop['is_customs_clearance'] ?? 0,
                'estimated_arrival_date' => $stop['estimated_arrival_date'] ?? null,
                'estimated_departure_date' => $stop['estimated_departure_date'] ?? null,
                'notes' => $stop['notes'] ?? null
            ]);

            if ($stop_result['success']) {
                $stop_id_map[$old_id] = $stop_result['stop_id'];

                // Add fees for this stop
                if (!empty($stop['fees'])) {
                    foreach ($stop['fees'] as $fee) {
                        add_stop_fee($conn, $stop_result['stop_id'], [
                            'fee_type' => $fee['fee_type'] ?? 'other',
                            'fee_name' => $fee['fee_name'] ?? '',
                            'rate' => floatval($fee['rate'] ?? 0),
                            'rate_unit' => $fee['rate_unit'] ?? 'per_pallet',
                            'quantity' => isset($fee['quantity']) ? intval($fee['quantity']) : null,
                            'duration_days' => isset($fee['duration_days']) ? intval($fee['duration_days']) : null,
                            'estimated_cost' => floatval($fee['estimated_cost'] ?? 0)
                        ]);
                    }
                }
            }
        }

        // Add legs with mapped stop IDs
        if (!empty($input['legs'])) {
            foreach ($input['legs'] as $leg) {
                $from_id = $stop_id_map[$leg['from_stop_id']] ?? $leg['from_stop_id'];
                $to_id = $stop_id_map[$leg['to_stop_id']] ?? $leg['to_stop_id'];

                // Verify stop IDs are valid
                if (!$from_id || !$to_id) continue;

                add_projection_leg($conn, $projection_id, [
                    'from_stop_id' => $from_id,
                    'to_stop_id' => $to_id,
                    'transport_mode' => $leg['transport_mode'] ?? 'truck',
                    'start_date' => $leg['start_date'] ?? null,
                    'end_date' => $leg['end_date'] ?? null,
                    'delivery_rate' => $leg['delivery_rate'] ?? null,
                    'delivery_rate_unit' => $leg['delivery_rate_unit'] ?? 'per_week',
                    'trucks_required' => $leg['trucks_required'] ?? null,
                    'freight_cost_per_truck' => $leg['freight_cost_per_truck'] ?? null,
                    'accessorial_cost_per_truck' => $leg['accessorial_cost_per_truck'] ?? null,
                    'total_freight_cost' => $leg['total_freight_cost'] ?? null,
                    'triggers_milestone' => $leg['triggers_milestone'] ?? null,
                    'notes' => $leg['notes'] ?? null
                ]);
            }
        }
    }

    // Recalculate costs
    calculate_projection_costs($conn, $projection_id);

    $conn->commit();

    // Load updated projection
    $projection = get_projection($conn, $projection_id);

    echo json_encode([
        'success' => true,
        'projection_id' => $projection_id,
        'projection' => $projection,
        'message' => $input['projection_id'] ? 'Projection updated successfully' : 'Projection created successfully'
    ]);

} catch (Throwable $e) {
    if ($conn && $conn->errno) {
        $error_message = $e->getMessage() . ' (DB: ' . $conn->error . ')';
    } else {
        $error_message = $e->getMessage();
    }

    if ($conn) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error_message]);
}

$conn->close();
