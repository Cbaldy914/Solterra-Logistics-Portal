<?php
/**
 * Warehouse Inventory Helper Functions
 * Extracted from manage_warehouse_inventory.php for reuse in warehouse.php
 */

/**
 * Fetch stored pallets/containers for warehouse inventory display
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @param string $received_status Status filter for received items
 * @param bool $is_port Whether this is a port facility
 * @return array [pallets_in_storage, total_pallets]
 */
function fetchStoredInventory($conn, $warehouse_id, $received_status, $is_port) {
    $pallets_in_storage = [];
    $total_pallets = 0;

    // Robust pallet query - INNER JOIN to only include pallets with deliveries to current warehouse
    $stmtP_Stored = $conn->prepare("
        SELECT
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            m.vendor_name AS origin_vendor,
            d_received.id AS received_delivery_id,
            d_received.bol_number AS received_bol,
            p_assigned.project_name AS assigned_project
        FROM inventory_pallets ip
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        INNER JOIN delivery_pallets dp_received ON ip.id = dp_received.inventory_pallet_id
        INNER JOIN deliveries d_received ON dp_received.delivery_id = d_received.id
            AND d_received.warehouse_id = ?
            AND d_received.status_of_delivery != 'Departed Port'
        LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
        WHERE ip.current_warehouse_id = ? AND ip.status = ?
        ORDER BY ip.arrival_date DESC, ip.id DESC
    ");

    if (!$stmtP_Stored) throw new Exception("Failed to prepare stored pallets query: " . $conn->error);
    $stmtP_Stored->bind_param("iis", $warehouse_id, $warehouse_id, $received_status);
    $stmtP_Stored->execute();
    $resultP_Stored = $stmtP_Stored->get_result();

    while ($pallet = $resultP_Stored->fetch_assoc()) {
        $pallets_in_storage[] = $pallet;
        $pallet['assigned_project'] = $pallet['assigned_project'] ?? 'N/A';
        $total_pallets++;
    }
    $stmtP_Stored->close();

    return [$pallets_in_storage, $total_pallets];
}

/**
 * PORT-SPECIFIC: Fetch containers grouped for port operations interface
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Port ID
 * @param string $received_status Status filter (typically 'Cleared Customs')
 * @return array Array of cleared containers with grouped data
 */
function fetchPortContainersCleared($conn, $warehouse_id, $received_status) {
    $containers_cleared = [];

        $stmtContainers = $conn->prepare("
            SELECT
                d_received.bol_number AS container_number,
                d_received.id AS delivery_id,
                MIN(ip.arrival_date) AS arrival_date,
                m.vendor_name AS origin_vendor,
                COUNT(ip.id) AS total_pallets,
                SUM(ip.quantity) AS total_modules,
                GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages,
                GROUP_CONCAT(DISTINCT p_assigned.project_name SEPARATOR ', ') AS projects
            FROM inventory_pallets ip
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            LEFT JOIN delivery_pallets dp_received ON ip.id = dp_received.inventory_pallet_id
            LEFT JOIN deliveries d_received ON dp_received.delivery_id = d_received.id AND d_received.warehouse_id = ?
            LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
        WHERE ip.current_warehouse_id = ?
            AND ip.status = ?
            AND d_received.status_of_delivery != 'Departed Port'
            GROUP BY d_received.bol_number, d_received.id, m.vendor_name
            ORDER BY MIN(ip.arrival_date) DESC
        ");

        if ($stmtContainers) {
            $stmtContainers->bind_param("iis", $warehouse_id, $warehouse_id, $received_status);
            $stmtContainers->execute();
            $resultContainers = $stmtContainers->get_result();

            while ($container = $resultContainers->fetch_assoc()) {
            // Create detailed wattage breakdown for this container
                $wattages = explode(', ', $container['wattages']);
                $wattage_details = [];
                foreach ($wattages as $wattage) {
                    $stmtWattageDetail = $conn->prepare("
                        SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                        FROM inventory_pallets ip
                        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                        WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status = ?
                    ");
                    if ($stmtWattageDetail) {
                        $stmtWattageDetail->bind_param("iis", $container['delivery_id'], $wattage, $received_status);
                        $stmtWattageDetail->execute();
                        $stmtWattageDetail->bind_result($pallet_count, $module_count);
                        if ($stmtWattageDetail->fetch()) {
                            $wattage_details[] = "{$wattage}W: {$pallet_count} pallets ({$module_count} modules)";
                        }
                        $stmtWattageDetail->close();
                    }
                }
                $container['wattage_breakdown'] = implode(' • ', $wattage_details);
                $container['projects'] = $container['projects'] ?? 'N/A';

                // Count pallets on customs hold for this container
                $stmtHold = $conn->prepare("
                    SELECT COUNT(ip.id) as hold_count, COALESCE(SUM(ip.quantity), 0) as hold_modules
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.status = 'Customs Hold'
                ");
                $container['hold_pallets'] = 0;
                $container['hold_modules'] = 0;
                if ($stmtHold) {
                    $stmtHold->bind_param("i", $container['delivery_id']);
                    $stmtHold->execute();
                    $stmtHold->bind_result($hold_count, $hold_modules);
                    if ($stmtHold->fetch()) {
                        $container['hold_pallets'] = (int)$hold_count;
                        $container['hold_modules'] = (int)$hold_modules;
                    }
                    $stmtHold->close();
                }

                $containers_cleared[] = $container;
            }
            $stmtContainers->close();
        }

    return $containers_cleared;
}

/**
 * PORT-SPECIFIC: Fetch individual pallets by status for customs workflow.
 *
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Port ID
 * @param string $status Pallet status to return (e.g., Cleared Customs, Customs Hold)
 * @return array
 */
function fetchPortPalletsByStatus($conn, $warehouse_id, $status) {
    $rows = [];

    $stmt = $conn->prepare("
        SELECT
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.arrival_date,
            COALESCE(ip.customs_hold_cost, 0) AS customs_hold_cost,
            ip.customs_hold_cost_notes,
            ip.customs_hold_cost_updated_at,
            COALESCE(NULLIF(TRIM(d.bol_number), ''), 'N/A') AS container_number,
            COALESCE(p.project_name, 'N/A') AS project_name,
            COALESCE(m.vendor_name, 'N/A') AS origin_vendor
        FROM inventory_pallets ip
        LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        LEFT JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN projects p ON ip.assigned_project_id = p.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        WHERE ip.current_warehouse_id = ?
          AND ip.status = ?
        ORDER BY d.bol_number ASC, ip.arrival_date DESC, ip.id DESC
    ");

    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param("is", $warehouse_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * Fetch pallets currently in transit to this facility
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of pallets in transit
 */
function fetchTransitPallets($conn, $warehouse_id) {
    $pallets_in_transit = [];

    $stmtP_Transit = $conn->prepare("
        SELECT
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            m.vendor_name AS origin_vendor,
            d.bol_number AS delivery_bol,
            d.anticipated_delivery_date AS est_arrival_date,
            d.id AS delivery_id,
            p.project_name AS source_project
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        JOIN deliveries d ON dp.delivery_id = d.id
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE ip.status IN ('In Transit to Warehouse', 'On Water')
            AND d.warehouse_id = ?
            AND d.status_of_delivery != 'Departed Port'
        ORDER BY d.anticipated_delivery_date ASC, ip.id DESC
    ");

    if (!$stmtP_Transit) throw new Exception("Failed to prepare transit pallets query: " . $conn->error);
    $stmtP_Transit->bind_param("i", $warehouse_id);
    $stmtP_Transit->execute();
    $resultP_Transit = $stmtP_Transit->get_result();

    while ($pallet = $resultP_Transit->fetch_assoc()) {
        $pallets_in_transit[] = $pallet;
        $pallet['source_project'] = $pallet['source_project'] ?? 'N/A';
    }
    $stmtP_Transit->close();

    return $pallets_in_transit;
}

/**
 * Fetch transit deliveries grouped by delivery/truckload
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of transit truckloads with grouped data
 */
function fetchTransitTruckloads($conn, $warehouse_id) {
    $transit_truckloads = [];

    $stmtTransitTruckloads = $conn->prepare("
        SELECT
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.anticipated_delivery_date AS est_arrival_date,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ?
        AND ip.status IN ('In Transit to Warehouse', 'On Water')
            AND d.status_of_delivery != 'Departed Port'
        GROUP BY d.id, d.bol_number, d.supplier, d.anticipated_delivery_date
        ORDER BY d.anticipated_delivery_date ASC
    ");

    if ($stmtTransitTruckloads) {
        $stmtTransitTruckloads->bind_param("i", $warehouse_id);
        $stmtTransitTruckloads->execute();
        $resultTransitTruckloads = $stmtTransitTruckloads->get_result();

        while ($truckload = $resultTransitTruckloads->fetch_assoc()) {
            // Create detailed wattage breakdown
            $wattages = explode(', ', $truckload['wattages']);
            $wattage_details = [];
            foreach ($wattages as $wattage) {
                $stmtWattageDetail = $conn->prepare("
                    SELECT COUNT(ip.id) as pallet_count, SUM(ip.quantity) as module_count
                    FROM inventory_pallets ip
                    JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
                    WHERE dp.delivery_id = ? AND ip.wattage = ? AND ip.status IN ('In Transit to Warehouse', 'On Water')
                ");
                if ($stmtWattageDetail) {
                    $stmtWattageDetail->bind_param("ii", $truckload['delivery_id'], $wattage);
                    $stmtWattageDetail->execute();
                    $stmtWattageDetail->bind_result($pallet_count, $module_count);
                    if ($stmtWattageDetail->fetch()) {
                        $wattage_details[] = "{$wattage}W: {$pallet_count} pallets ({$module_count} modules)";
                    }
                    $stmtWattageDetail->close();
                }
            }
            $truckload['wattage_breakdown'] = implode(' • ', $wattage_details);
            $transit_truckloads[] = $truckload;
            $truckload['projects'] = $truckload['projects'] ?? 'N/A';
        }
        $stmtTransitTruckloads->close();
    }

    return $transit_truckloads;
}

/**
 * Fetch inbound delivery history for this facility
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of inbound delivery history grouped by BOL
 */
function fetchInboundHistory($conn, $warehouse_id) {
    $inbound_history = [];

    $stmtInboundHistory = $conn->prepare("
        SELECT
            d.bol_number,
            d.supplier,
            d.warehouse_arrival_date,
            d.proof_of_delivery,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.warehouse_id = d.warehouse_id AND d_inner.warehouse_arrival_date = d.warehouse_arrival_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
            GROUP_CONCAT(DISTINCT d.project_id ORDER BY d.project_id SEPARATOR ',') AS project_ids,
            CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage,
            (SELECT COUNT(*) FROM project_documents pd
             WHERE pd.delivery_id IN (SELECT d_inner.id FROM deliveries d_inner
                                     WHERE d_inner.bol_number = d.bol_number
                                     AND d_inner.warehouse_id = d.warehouse_id
                                     AND d_inner.warehouse_arrival_date = d.warehouse_arrival_date)
             AND pd.document_type = 'pods'
             AND pd.document_sub_type = 'Warehouse POD') AS has_warehouse_pod
        FROM deliveries d
        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ?
        AND d.status_of_delivery IN ('Delivered to Warehouse', 'Cleared Customs', 'Departed Port')
        AND d.warehouse_arrival_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.warehouse_arrival_date, d.proof_of_delivery
        ORDER BY d.warehouse_arrival_date DESC
    ");

    if ($stmtInboundHistory) {
        $stmtInboundHistory->bind_param("i", $warehouse_id);
        $stmtInboundHistory->execute();
        $resultInboundHistory = $stmtInboundHistory->get_result();
        $index = 0;

        while ($delivery = $resultInboundHistory->fetch_assoc()) {
            $delivery['source_project'] = $delivery['projects'] ?? 'N/A';
            $delivery['index'] = $index;

            // Handle mixed wattage deliveries with detailed breakdown
            if ($delivery['is_mixed_wattage']) {
                $delivery_ids = explode(',', $delivery['delivery_ids']);
                $delivery['details'] = [];

                foreach ($delivery_ids as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.project_id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.project_id, d.wattage, d.quantity, p.project_name
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("i", $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $delivery['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }

            $inbound_history[] = $delivery;
            $index++;
        }
        $stmtInboundHistory->close();
    }

    return $inbound_history;
}

/**
 * Fetch outbound delivery history for this facility
 * @param mysqli $conn Database connection
 * @param int $warehouse_id Warehouse/port ID
 * @return array Array of outbound delivery history grouped by BOL
 */
function fetchOutboundHistory($conn, $warehouse_id) {
    $outbound_history = [];

    $stmtOutboundHistory = $conn->prepare("
        SELECT
            d.bol_number,
            d.supplier,
            d.left_warehouse_date AS departure_date,
            d.anticipated_delivery_date,
            d.status_of_delivery,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.supplier = d.supplier AND d_inner.left_warehouse_date = d.left_warehouse_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
            GROUP_CONCAT(DISTINCT d.project_id ORDER BY d.project_id SEPARATOR ',') AS project_ids,
            CASE WHEN COUNT(DISTINCT d.wattage) > 1 THEN 1 ELSE 0 END AS is_mixed_wattage,
            GROUP_CONCAT(DISTINCT
                CASE
                    WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN d.warehouse_id IS NOT NULL AND d.warehouse_id != ? THEN CONCAT('Warehouse: ', w.name)
                    ELSE 'Unknown Destination'
                END SEPARATOR ', '
            ) AS destinations
        FROM deliveries d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE (d.warehouse_id = ? OR (d.origin_type = 'warehouse' AND d.origin_id = ?))
        AND d.left_warehouse_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.left_warehouse_date, d.anticipated_delivery_date, d.status_of_delivery
        ORDER BY d.left_warehouse_date DESC
    ");

    if ($stmtOutboundHistory) {
        $stmtOutboundHistory->bind_param("iii", $warehouse_id, $warehouse_id, $warehouse_id);
        $stmtOutboundHistory->execute();
        $resultOutboundHistory = $stmtOutboundHistory->get_result();
        $index = 0;

        while ($delivery = $resultOutboundHistory->fetch_assoc()) {
            $delivery['destination_project'] = $delivery['projects'] ?? 'N/A';
            $delivery['index'] = $index;

            // Handle mixed wattage deliveries with detailed breakdown
            if ($delivery['is_mixed_wattage']) {
                $delivery_ids = explode(',', $delivery['delivery_ids']);
                $delivery['details'] = [];

                foreach ($delivery_ids as $del_id) {
                    $stmtDetail = $conn->prepare("
                        SELECT d.id, d.project_id, d.wattage, d.quantity, p.project_name,
                               COUNT(dp.inventory_pallet_id) AS pallet_count,
                               CASE
                                   WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                                   WHEN d.warehouse_id IS NOT NULL AND d.warehouse_id != ? THEN CONCAT('Warehouse: ', w2.name)
                                   ELSE 'Unknown Destination'
                               END AS destination
                        FROM deliveries d
                        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
                        LEFT JOIN projects p ON d.project_id = p.id
                        LEFT JOIN warehouses w2 ON d.warehouse_id = w2.id
                        WHERE d.id = ?
                        GROUP BY d.id, d.project_id, d.wattage, d.quantity, p.project_name, destination
                    ");
                    if ($stmtDetail) {
                        $stmtDetail->bind_param("ii", $warehouse_id, $del_id);
                        $stmtDetail->execute();
                        $resultDetail = $stmtDetail->get_result();
                        if ($detail = $resultDetail->fetch_assoc()) {
                            $delivery['details'][] = $detail;
                        }
                        $stmtDetail->close();
                    }
                }
            }

            $outbound_history[] = $delivery;
            $index++;
        }
        $stmtOutboundHistory->close();
    }

    return $outbound_history;
}
