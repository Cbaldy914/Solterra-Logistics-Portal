<?php
/**
 * Sunny AI Tools
 * Provides pre-built functions for common logistics queries
 * Updated to match actual database schema focusing on PODs, BOLs, Flash Test Data, inventory, and project status
 * Note: 'supplier' field refers to the manufacturer (e.g., "Trina Solar"), not the shipping carrier
 */

require_once __DIR__ . '/query-executor.php';

class SunnyTools {
    private $queryExecutor;
    private $userRole;
    private $userAccountId;
    
    public function __construct($userRole, $userAccountId = null) {
        $this->userRole = $userRole;
        $this->userAccountId = $userAccountId;
        $this->queryExecutor = new SunnyQueryExecutor($userRole, $userAccountId);
    }
    
    /**
     * Get project summary with MW calculations and delivery status
     */
    public function getProjectSummary($projectName = null, $limit = 10) {
        try {
            // First, get basic project info
            $sql = "
                SELECT 
                    p.id,
                    p.project_name,
                    p.project_size as stored_project_size,
                    p.estimated_completion_date,
                    p.project_address,
                    p.city,
                    p.state,
                    p.created_at,
                    -- Calculate delivered MWs
                    SUM(CASE WHEN ip.status = 'Delivered to Project' THEN (ip.wattage * ip.quantity) ELSE 0 END) / 1000000 as total_delivered_mw,
                    -- Calculate MWs in storage
                    SUM(CASE WHEN ip.status = 'In Warehouse' THEN (ip.wattage * ip.quantity) ELSE 0 END) / 1000000 as mw_in_storage
                FROM projects p
                LEFT JOIN inventory_pallets ip ON p.id = ip.assigned_project_id
            ";
            
            $params = [];
            $conditions = [];
            if ($projectName) {
                // Normalize and sanitize common quoting/punctuation around names
                $projectName = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $projectName);
                $projectName = trim($projectName, " \t\n\r\0\x0B\"'");
                $conditions[] = "p.project_name LIKE ?";
                $params[] = "%{$projectName}%";
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $conditions[] = "p.account_id = ?";
                $params[] = $this->userAccountId;
            }
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
            
            $sql .= " GROUP BY p.id ORDER BY p.created_at DESC LIMIT {$limit}";
            
            $result = $this->queryExecutor->executeQuery($sql, $params);
            
            // Post-process to calculate project size and remaining MWs
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as &$project) {
                    $projectId = $project['id'];
                    
                    // --- Project Size: always derive from project_wattage_orders ---
                    $sizeSql = "SELECT SUM(CAST(wattage AS UNSIGNED) * total_order) / 1000000 AS calculated_size FROM project_wattage_orders WHERE project_id = ?";
                    $sizeResult = $this->queryExecutor->executeQuery($sizeSql, [$projectId]);
                    $project['project_size_mw'] = 0.0;
                    if ($sizeResult['success'] && !empty($sizeResult['data'])) {
                        $project['project_size_mw'] = floatval($sizeResult['data'][0]['calculated_size'] ?? 0);
                    }
                    
                    // -------------------------------------------------------------------------
                    // Recalculate Delivered and Storage MWs using the same logic as dashboard
                    // -------------------------------------------------------------------------
                    // 1) Delivered MWs via deliveries table (status_of_delivery = 'Delivered to Project')
                    $deliveredSql = "SELECT SUM(wattage * quantity) / 1000000 AS delivered_mw,
                                           SUM(quantity) AS delivered_modules
                                    FROM deliveries
                                    WHERE project_id = ? AND status_of_delivery = 'Delivered to Project'";
                    $deliveredRes = $this->queryExecutor->executeQuery($deliveredSql, [$projectId]);
                    $deliveredMW = 0.0;
                    $deliveredModules = 0;
                    if ($deliveredRes['success'] && !empty($deliveredRes['data'])) {
                        $deliveredMW = floatval($deliveredRes['data'][0]['delivered_mw']);
                        $deliveredModules = intval($deliveredRes['data'][0]['delivered_modules'] ?? 0);
                    }

                    if ($deliveredMW == 0) {
                        // Fallback: use inventory_pallets that have made it to the project
                        $deliveredInvSql = "SELECT SUM(ip.wattage * ip.quantity) / 1000000 AS delivered_mw,
                                                  SUM(ip.quantity) AS delivered_modules
                                            FROM inventory_pallets ip
                                            WHERE ip.status = 'Delivered to Project' AND (ip.assigned_project_id = ? OR ip.current_project_id = ?)";
                        $deliveredInvRes = $this->queryExecutor->executeQuery($deliveredInvSql, [$projectId, $projectId]);
                        if ($deliveredInvRes['success'] && !empty($deliveredInvRes['data'])) {
                            $deliveredMW = floatval($deliveredInvRes['data'][0]['delivered_mw']);
                            $deliveredModules = intval($deliveredInvRes['data'][0]['delivered_modules'] ?? 0);
                        }
                    }

                    // 2) MW and modules currently sitting in warehouse
                    $storageSql = "SELECT SUM(ip.wattage * ip.quantity) / 1000000 AS storage_mw,
                                         SUM(ip.quantity) AS storage_modules,
                                         COUNT(ip.id) AS storage_pallets
                                    FROM inventory_pallets ip
                                    WHERE ip.status = 'In Warehouse' AND (ip.assigned_project_id = ? OR ip.current_project_id = ?)";
                    $storageRes = $this->queryExecutor->executeQuery($storageSql, [$projectId, $projectId]);
                    $storageMW = 0.0;
                    $storageModules = 0;
                    $storagePallets = 0;
                    if ($storageRes['success'] && !empty($storageRes['data'])) {
                        $storageMW = floatval($storageRes['data'][0]['storage_mw']);
                        $storageModules = intval($storageRes['data'][0]['storage_modules'] ?? 0);
                        $storagePallets = intval($storageRes['data'][0]['storage_pallets'] ?? 0);
                    }

                    // Get total project size in modules for conversion
                    $projectSizeModulesSql = "SELECT SUM(total_order) AS total_modules FROM project_wattage_orders WHERE project_id = ?";
                    $projectSizeModulesRes = $this->queryExecutor->executeQuery($projectSizeModulesSql, [$projectId]);
                    $projectSizeModules = 0;
                    if ($projectSizeModulesRes['success'] && !empty($projectSizeModulesRes['data'])) {
                        $projectSizeModules = intval($projectSizeModulesRes['data'][0]['total_modules'] ?? 0);
                    }

                    // Overwrite the earlier quick-join calculations with the more accurate figures
                    $project['total_delivered_mw'] = $deliveredMW;
                    $project['total_delivered_modules'] = $deliveredModules;
                    $project['mw_in_storage_raw']  = $storageMW;
                    $project['modules_in_storage'] = $storageModules;
                    $project['pallets_in_storage'] = $storagePallets;
                    $project['project_size_modules'] = $projectSizeModules;

                    // Calculate remaining MW and modules
                    $project['remaining_mw'] = round($project['project_size_mw'] - $deliveredMW, 3);
                    $project['remaining_modules'] = $projectSizeModules - $deliveredModules;

                    // -----------------------------------------------------------------
                    // NEW: Fetch wattage breakdown (wattage => total_order)
                    // -----------------------------------------------------------------
                    $wattageSql = "SELECT wattage, total_order FROM project_wattage_orders WHERE project_id = ? ORDER BY CAST(wattage AS UNSIGNED)";
                    $wattageRes = $this->queryExecutor->executeQuery($wattageSql, [$projectId]);
                    if ($wattageRes['success'] && !empty($wattageRes['data'])) {
                        $project['wattage_breakdown'] = array_map(function($row) {
                            return [
                                'wattage' => $row['wattage'],
                                'total_order' => intval($row['total_order'])
                            ];
                        }, $wattageRes['data']);
                    } else {
                        $project['wattage_breakdown'] = [];
                    }

                    // Format storage as single field (only show if > 0)
                    if ($storageMW > 0) {
                        $project['mw_in_storage'] = round($storageMW, 3) . ' MW';
                    } else {
                        $project['mw_in_storage'] = null; // Don't show storage if 0
                    }
                    
                    // Remove stored project size field to avoid confusion
                    unset($project['stored_project_size']);
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get delivery status with BOL and POD information
     * Note: supplier refers to manufacturer (e.g., "Trina Solar"), not shipping carrier
     */
    public function getDeliveryStatus($projectId = null, $status = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.bol_number,
                    d.supplier as manufacturer,
                    d.status_of_delivery,
                    d.wattage,
                    d.quantity,
                    d.anticipated_delivery_date,
                    d.actual_delivery_date,
                    d.warehouse_arrival_date,
                    d.left_warehouse_date,
                    d.proof_of_delivery,
                    d.freight_cost,
                    d.accessorial_costs,
                    d.pay_status,
                    p.project_name,
                    w.name as warehouse_name,
                    -- Calculate MW for this delivery
                    (d.wattage * d.quantity) / 1000000 as delivery_mw
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN warehouses w ON d.warehouse_id = w.id
                WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($projectId) {
                $sql .= " AND d.project_id = ?";
                $params[] = $projectId;
            }
            
            if ($status) {
                $sql .= " AND d.status_of_delivery = ?";
                $params[] = $status;
            }
            
            // Tenant scoping
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY d.anticipated_delivery_date DESC LIMIT 50";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get upcoming deliveries within specified date range
     */
    public function getUpcomingDeliveries($projectId = null, $weeks = 4) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.bol_number,
                    d.supplier as manufacturer,
                    d.status_of_delivery,
                    d.wattage,
                    d.quantity,
                    d.anticipated_delivery_date,
                    d.actual_delivery_date,
                    p.project_name,
                    w.name as warehouse_name,
                    -- Calculate MW and pallet count
                    (d.wattage * d.quantity) / 1000000 as delivery_mw,
                    CEIL(d.quantity / 30) as estimated_pallets
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN warehouses w ON d.warehouse_id = w.id
                WHERE d.anticipated_delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? WEEK)
                AND d.actual_delivery_date IS NULL
            ";
            
            $params = [$weeks];
            
            if ($projectId) {
                $sql .= " AND d.project_id = ?";
                $params[] = $projectId;
            }
            // Tenant scoping
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY d.anticipated_delivery_date ASC LIMIT 50";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get warehouse inventory with comprehensive details (matches warehouse_info.php approach)
     */
    public function getWarehouseInventory($warehouseId = null) {
        try {
            // Get warehouse basic info with inventory summary, scoped to account via project joins
            $sql = "
                SELECT 
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    w.address,
                    w.city,
                    w.state,
                    COUNT(ip.id) as pallets_in_storage,
                    SUM(ip.quantity) as modules_in_storage,
                    SUM(ip.wattage * ip.quantity) / 1000000 as mw_in_storage,
                    AVG(CASE WHEN ip.arrival_date IS NOT NULL THEN DATEDIFF(CURDATE(), ip.arrival_date) ELSE NULL END) as avg_days_stored,
                    GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage) as wattages_available,
                    COUNT(DISTINCT COALESCE(ip.assigned_project_id, ip.current_project_id)) as projects_with_inventory
                FROM warehouses w
                LEFT JOIN inventory_pallets ip ON w.id = ip.current_warehouse_id AND ip.status = 'In Warehouse'
                LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
                LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
            ";

            $params = [];
            $clauses = [];
            if ($warehouseId) {
                $clauses[] = "w.id = ?";
                $params[] = $warehouseId;
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $clauses[] = "(p_assigned.account_id = ? OR p_current.account_id = ?)";
                $params[] = $this->userAccountId;
                $params[] = $this->userAccountId;
            }
            if (!empty($clauses)) {
                $sql .= " WHERE " . implode(' AND ', $clauses);
            }

            $sql .= " GROUP BY w.id ORDER BY w.name";

            $result = $this->queryExecutor->executeQuery($sql, $params);
            
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as &$warehouse) {
                    $warehouseId = $warehouse['warehouse_id'];
                    
                    // Get delivery activity (inbound/outbound counts from last 30 days)
                    $deliverySql = "
                        SELECT 
                            COUNT(CASE WHEN d.warehouse_arrival_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_inbound,
                            COUNT(CASE WHEN d.left_warehouse_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_outbound,
                            COUNT(CASE WHEN d.warehouse_arrival_date IS NOT NULL THEN 1 END) as total_inbound,
                            COUNT(CASE WHEN d.left_warehouse_date IS NOT NULL THEN 1 END) as total_outbound
                        FROM deliveries d
                        LEFT JOIN projects p ON d.project_id = p.id
                        WHERE d.warehouse_id = ?";
                    $deliveryParams = [$warehouseId];
                    if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                        $deliverySql .= " AND p.account_id = ?";
                        $deliveryParams[] = $this->userAccountId;
                    }
                    
                    $deliveryResult = $this->queryExecutor->executeQuery($deliverySql, $deliveryParams);
                    if ($deliveryResult['success'] && !empty($deliveryResult['data'])) {
                        $warehouse = array_merge($warehouse, $deliveryResult['data'][0]);
                    }
                    
                    // Fetch warehouse cost items
                    $costSql = "SELECT trigger_event, amount FROM warehouse_cost_items WHERE warehouse_id = ? AND is_active = 1";
                    $costResult = $this->queryExecutor->executeQuery($costSql, [$warehouseId]);
                    
                    $monthlyStorageFee = 0;
                    $inFee = 0;
                    $outFee = 0;
                    
                    if ($costResult['success'] && !empty($costResult['data'])) {
                        foreach ($costResult['data'] as $cost) {
                            switch ($cost['trigger_event']) {
                                case 'entry':
                                    $inFee = floatval($cost['amount']);
                                    break;
                                case 'exit':
                                    $outFee = floatval($cost['amount']);
                                    break;
                                case 'monthly':
                                    $monthlyStorageFee = floatval($cost['amount']);
                                    break;
                            }
                        }
                    }
                    
                    // Add cost data to warehouse array for API response
                    $warehouse['in_fee'] = $inFee;
                    $warehouse['out_fee'] = $outFee;
                    $warehouse['monthly_storage_fee'] = $monthlyStorageFee;
                    
                    // Calculate estimated costs based on warehouse_info.php logic
                    $palletsInStorage = intval($warehouse['pallets_in_storage'] ?? 0);
                    $avgDaysStored = floatval($warehouse['avg_days_stored'] ?? 0);
                    $totalInbound = intval($warehouse['total_inbound'] ?? 0);
                    $totalOutbound = intval($warehouse['total_outbound'] ?? 0);
                    
                    // Calculate costs (matching warehouse_info.php calculations)
                    $dailyStorageRate = $monthlyStorageFee / 30;
                    $estimatedStorageCost = $palletsInStorage * $dailyStorageRate * $avgDaysStored;
                    $totalInFees = $inFee * $totalInbound;
                    $totalOutFees = $outFee * $totalOutbound;
                    $totalEstimatedCost = $estimatedStorageCost + $totalInFees + $totalOutFees;
                    
                    // Add calculated fields
                    $warehouse['estimated_storage_cost'] = round($estimatedStorageCost, 2);
                    $warehouse['total_in_fees'] = round($totalInFees, 2);
                    $warehouse['total_out_fees'] = round($totalOutFees, 2);
                    $warehouse['total_estimated_cost'] = round($totalEstimatedCost, 2);
                    $warehouse['avg_days_stored'] = round($avgDaysStored, 1);
                    $warehouse['mw_in_storage'] = round(floatval($warehouse['mw_in_storage'] ?? 0), 3);
                    
                    // Get top projects by module count
                    $projectSql = "
                        SELECT 
                            p.project_name,
                            COUNT(ip.id) as pallet_count,
                            SUM(ip.quantity) as module_count,
                            SUM(ip.wattage * ip.quantity) / 1000000 as project_mw_in_storage
                        FROM inventory_pallets ip
                        JOIN projects p ON ip.assigned_project_id = p.id
                        WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'";
                    $projectParams = [$warehouseId];
                    if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                        $projectSql .= " AND p.account_id = ?";
                        $projectParams[] = $this->userAccountId;
                    }
                    $projectSql .= " GROUP BY p.id, p.project_name ORDER BY module_count DESC LIMIT 5";

                    $projectResult = $this->queryExecutor->executeQuery($projectSql, $projectParams);
                    if ($projectResult['success'] && !empty($projectResult['data'])) {
                        $warehouse['top_projects'] = $projectResult['data'];
                    } else {
                        $warehouse['top_projects'] = [];
                    }
                    
                    // Format summary text
                    $modulesInStorage = intval($warehouse['modules_in_storage'] ?? 0);
                    $mwInStorage = $warehouse['mw_in_storage'];
                    if ($modulesInStorage > 0) {
                        $warehouse['inventory_summary'] = "{$palletsInStorage} pallets, {$modulesInStorage} modules ({$mwInStorage} MW) in storage";
                        if ($avgDaysStored > 0) {
                            $warehouse['inventory_summary'] .= " (avg {$warehouse['avg_days_stored']} days stored)";
                        }
                    } else {
                        $warehouse['inventory_summary'] = "No inventory currently in storage";
                    }
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get flash test data for projects within date range
     */
    public function getFlashTestData($projectId = null, $days = 30, $limit = 50) {
        try {
            $sql = "
                SELECT 
                    ftd.id,
                    ftd.module_id,
                    ftd.flash_date,
                    ftd.flash_result,
                    p.project_name,
                    p.id as project_id
                FROM flash_test_data ftd
                LEFT JOIN projects p ON ftd.project_id = p.id
                WHERE ftd.flash_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($projectId) {
                $sql .= " AND ftd.project_id = ?";
                $params[] = $projectId;
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY ftd.flash_date DESC LIMIT {$limit}";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get pallet movements and status changes
     */
    public function getPalletMovements($projectId = null, $warehouseId = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    ip.id,
                    ip.pallet_identifier,
                    ip.wattage,
                    ip.quantity,
                    ip.status,
                    ip.arrival_date,
                    ip.updated_at,
                    wh.name as current_warehouse,
                    p_assigned.project_name as assigned_project,
                    p_current.project_name as current_project,
                    -- Calculate MW for this pallet
                    (ip.wattage * ip.quantity) / 1000000 as pallet_mw
                FROM inventory_pallets ip
                LEFT JOIN warehouses wh ON ip.current_warehouse_id = wh.id
                LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
                LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
                WHERE ip.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($projectId) {
                $sql .= " AND (ip.assigned_project_id = ? OR ip.current_project_id = ?)";
                $params[] = $projectId;
                $params[] = $projectId;
            }
            
            if ($warehouseId) {
                $sql .= " AND ip.current_warehouse_id = ?";
                $params[] = $warehouseId;
            }
            
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND (p_assigned.account_id = ? OR p_current.account_id = ?)";
                $params[] = $this->userAccountId;
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY ip.updated_at DESC LIMIT 100";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get BOL (Bill of Lading) information
     * Note: supplier refers to manufacturer (e.g., "Trina Solar"), not shipping carrier
     */
    public function getBOLInformation($bolNumber = null, $days = 60) {
        try {
            $sql = "
                SELECT 
                    d.id as delivery_id,
                    d.bol_number,
                    d.supplier as manufacturer,
                    d.wattage,
                    d.quantity,
                    d.status_of_delivery,
                    d.anticipated_delivery_date,
                    d.actual_delivery_date,
                    d.proof_of_delivery,
                    p.project_name,
                    ss.start_time as scheduled_time,
                    ss.arrival_time,
                    ss.departure_time,
                    ss.bol_file,
                    -- Calculate MW and estimated pallets
                    (d.wattage * d.quantity) / 1000000 as delivery_mw,
                    CEIL(d.quantity / 30) as estimated_pallets
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN site_scheduling ss ON d.bol_number = ss.bol_number
                WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($bolNumber) {
                $sql .= " AND d.bol_number LIKE ?";
                $params[] = "%{$bolNumber}%";
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY d.anticipated_delivery_date DESC LIMIT 50";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get POD (Proof of Delivery) status
     * Note: supplier refers to manufacturer (e.g., "Trina Solar"), not shipping carrier
     */
    public function getPODStatus($projectId = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.bol_number,
                    d.supplier as manufacturer,
                    d.proof_of_delivery,
                    d.actual_delivery_date,
                    d.status_of_delivery,
                    d.wattage,
                    d.quantity,
                    p.project_name,
                    -- Calculate MW for this delivery
                    (d.wattage * d.quantity) / 1000000 as delivery_mw,
                    CASE 
                        WHEN d.proof_of_delivery IS NOT NULL THEN 'Available'
                        WHEN d.actual_delivery_date IS NOT NULL THEN 'Delivered - POD Missing'
                        ELSE 'Not Delivered'
                    END as pod_status
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($projectId) {
                $sql .= " AND d.project_id = ?";
                $params[] = $projectId;
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " ORDER BY d.actual_delivery_date DESC LIMIT 50";
            
            $result = $this->queryExecutor->executeQuery($sql, $params);
            
            // Post-process to inject front-end URLs for any available PODs
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as &$row) {
                    if (!empty($row['proof_of_delivery'])) {
                        // Generate view link (same as portal uses)
                        $row['pod_url'] = 'view_pod?delivery_id=' . $row['id'];
                    } else {
                        $row['pod_url'] = null;
                    }
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get project cost analysis with freight and accessorial costs
     */
    public function getProjectCostAnalysis($projectId = null) {
        try {
            $sql = "
                SELECT 
                    p.id,
                    p.project_name,
                    p.solterra_fee,
                    COUNT(DISTINCT d.id) as delivery_count,
                    SUM(d.freight_cost) as total_freight_cost,
                    SUM(d.accessorial_costs) as total_accessorial_costs,
                    SUM(d.customer_cost) as total_customer_costs,
                    AVG(d.freight_cost) as avg_freight_per_delivery,
                    SUM(ap.amount) as total_payables,
                    -- Calculate total MW delivered
                    SUM(d.wattage * d.quantity) / 1000000 as total_delivered_mw
                FROM projects p
                LEFT JOIN deliveries d ON p.id = d.project_id
                LEFT JOIN accounts_payable ap ON p.id = ap.project_id
            ";
            
            $params = [];
            if ($projectId) {
                $sql .= " WHERE p.id = ?";
                $params[] = $projectId;
            }
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= empty($params) ? " WHERE" : " AND";
                $sql .= " p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " GROUP BY p.id ORDER BY total_freight_cost DESC LIMIT 20";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delivery performance metrics grouped by manufacturer, warehouse, or project
     */
    public function getDeliveryPerformance($days = 30, $groupBy = 'manufacturer') {
        try {
            $groupMap = [
                'manufacturer' => 'd.supplier',
                'warehouse' => 'w.name',
                'project' => 'p.project_name'
            ];
            $groupExpr = $groupMap[strtolower($groupBy)] ?? $groupMap['manufacturer'];

            $sql = "
                SELECT ".$groupExpr." AS group_key,
                    COUNT(*) AS total_deliveries,
                    SUM(CASE WHEN d.status_of_delivery = 'Delivered to Project' THEN 1 ELSE 0 END) AS delivered_count,
                    SUM(CASE WHEN d.status_of_delivery = 'Delivered to Project'
                              AND d.actual_delivery_date IS NOT NULL
                              AND d.anticipated_delivery_date IS NOT NULL
                              AND d.actual_delivery_date <= d.anticipated_delivery_date
                        THEN 1 ELSE 0 END) AS on_time_count,
                    SUM(CASE WHEN d.actual_delivery_date IS NOT NULL AND d.proof_of_delivery IS NULL THEN 1 ELSE 0 END) AS missing_pod_count,
                    SUM((d.wattage * d.quantity) / 1000000) AS total_mw,
                    AVG(CASE WHEN d.actual_delivery_date IS NOT NULL AND d.warehouse_arrival_date IS NOT NULL
                             THEN DATEDIFF(d.actual_delivery_date, d.warehouse_arrival_date) ELSE NULL END) AS avg_transit_days
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN warehouses w ON d.warehouse_id = w.id
                WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            $params = [$days];
            if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                $sql .= " AND p.account_id = ?";
                $params[] = $this->userAccountId;
            }

            $sql .= " GROUP BY group_key ORDER BY total_deliveries DESC LIMIT 20";
            return $this->queryExecutor->executeQuery($sql, $params);

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * KPI Dashboard aggregates for the account
     */
    public function getKPIDashboard($days = 30) {
        try {
            $accountId = $this->userAccountId;
            $isScoped = ($accountId !== null && $this->userRole !== 'global_admin');
            $kpi = [
                'delivered_mw' => 0.0,
                'mw_in_storage' => 0.0,
                'on_time_rate' => 0.0,
                'missing_pods' => 0,
                'recent_inbound_pallets' => 0,
                'recent_outbound_pallets' => 0,
                'top_bottlenecks' => []
            ];

            // Delivered MW in window
            $sql = "SELECT SUM(d.wattage * d.quantity) / 1000000 AS mw
                    FROM deliveries d
                    JOIN projects p ON d.project_id = p.id
                    WHERE d.status_of_delivery = 'Delivered to Project'
                      AND d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            if ($isScoped) { $sql .= " AND p.account_id = ?"; $params[] = $accountId; }
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success'] && !empty($res['data'])) { $kpi['delivered_mw'] = round(floatval($res['data'][0]['mw'] ?? 0), 3); }

            // MW in storage (current)
            $sql = "SELECT SUM(ip.wattage * ip.quantity) / 1000000 AS mw
                    FROM inventory_pallets ip
                    LEFT JOIN projects pa ON ip.assigned_project_id = pa.id
                    LEFT JOIN projects pc ON ip.current_project_id = pc.id
                    WHERE ip.status = 'In Warehouse'";
            $params = [];
            if ($isScoped) { $sql .= " AND (pa.account_id = ? OR pc.account_id = ?)"; $params[] = $accountId; $params[] = $accountId; }
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success'] && !empty($res['data'])) { $kpi['mw_in_storage'] = round(floatval($res['data'][0]['mw'] ?? 0), 3); }

            // On-time delivery rate
            $sql = "SELECT 
                        SUM(CASE WHEN d.status_of_delivery = 'Delivered to Project' THEN 1 ELSE 0 END) AS delivered_count,
                        SUM(CASE WHEN d.status_of_delivery = 'Delivered to Project' 
                                  AND d.actual_delivery_date IS NOT NULL 
                                  AND d.anticipated_delivery_date IS NOT NULL 
                                  AND d.actual_delivery_date <= d.anticipated_delivery_date THEN 1 ELSE 0 END) AS on_time_count
                    FROM deliveries d
                    JOIN projects p ON d.project_id = p.id
                    WHERE d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            if ($isScoped) { $sql .= " AND p.account_id = ?"; $params[] = $accountId; }
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success'] && !empty($res['data'])) {
                $del = intval($res['data'][0]['delivered_count'] ?? 0);
                $ont = intval($res['data'][0]['on_time_count'] ?? 0);
                $kpi['on_time_rate'] = $del > 0 ? round($ont / $del, 3) : 0.0;
            }

            // Missing PODs
            $sql = "SELECT COUNT(*) AS missing_pods
                    FROM deliveries d
                    JOIN projects p ON d.project_id = p.id
                    WHERE d.actual_delivery_date IS NOT NULL
                      AND d.proof_of_delivery IS NULL
                      AND d.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params = [$days];
            if ($isScoped) { $sql .= " AND p.account_id = ?"; $params[] = $accountId; }
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success'] && !empty($res['data'])) { $kpi['missing_pods'] = intval($res['data'][0]['missing_pods'] ?? 0); }

            // Inbound/outbound pallets (window)
            $sql = "SELECT 
                        SUM(CASE WHEN d.warehouse_arrival_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS recent_inbound,
                        SUM(CASE WHEN d.left_warehouse_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS recent_outbound
                    FROM deliveries d
                    JOIN projects p ON d.project_id = p.id";
            $params = [$days, $days];
            if ($isScoped) { $sql .= " WHERE p.account_id = ?"; $params[] = $accountId; }
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success'] && !empty($res['data'])) {
                $kpi['recent_inbound_pallets'] = intval($res['data'][0]['recent_inbound'] ?? 0);
                $kpi['recent_outbound_pallets'] = intval($res['data'][0]['recent_outbound'] ?? 0);
            }

            // Top bottlenecks (warehouses by avg days stored)
            $sql = "SELECT w.name AS warehouse_name,
                           AVG(CASE WHEN ip.arrival_date IS NOT NULL THEN DATEDIFF(CURDATE(), ip.arrival_date) ELSE NULL END) AS avg_days,
                           COUNT(ip.id) AS pallets
                    FROM inventory_pallets ip
                    JOIN warehouses w ON ip.current_warehouse_id = w.id
                    LEFT JOIN projects pa ON ip.assigned_project_id = pa.id
                    LEFT JOIN projects pc ON ip.current_project_id = pc.id
                    WHERE ip.status = 'In Warehouse'";
            $params = [];
            if ($isScoped) { $sql .= " AND (pa.account_id = ? OR pc.account_id = ?)"; $params[] = $accountId; $params[] = $accountId; }
            $sql .= " GROUP BY w.id, w.name ORDER BY avg_days DESC LIMIT 3";
            $res = $this->queryExecutor->executeQuery($sql, $params);
            if ($res['success']) { $kpi['top_bottlenecks'] = $res['data']; }

            return ['success' => true, 'data' => $kpi];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Search for specific information across multiple tables
     */
    public function searchLogistics($searchTerm, $searchType = 'all') {
        try {
            $results = [];
            
            // Search projects
            if ($searchType === 'all' || $searchType === 'projects') {
                $sql = "SELECT id, project_name, project_address, city, state FROM projects WHERE project_name LIKE ?";
                $p = ["%{$searchTerm}%"];
                if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                    $sql .= " AND account_id = ?";
                    $p[] = $this->userAccountId;
                }
                $sql .= " LIMIT 10";
                $projectResults = $this->queryExecutor->executeQuery($sql, $p);
                if ($projectResults['success']) {
                    $results['projects'] = $projectResults['data'];
                }
            }
            
            // Search deliveries by BOL number or manufacturer
            if ($searchType === 'all' || $searchType === 'deliveries') {
                $sql = "SELECT d.id, d.bol_number, d.supplier as manufacturer, d.status_of_delivery, p.project_name,
                              (d.wattage * d.quantity) / 1000000 as delivery_mw
                       FROM deliveries d 
                       LEFT JOIN projects p ON d.project_id = p.id 
                       WHERE (d.bol_number LIKE ? OR d.supplier LIKE ?)";
                $p = ["%{$searchTerm}%", "%{$searchTerm}%"];
                if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                    $sql .= " AND p.account_id = ?";
                    $p[] = $this->userAccountId;
                }
                $sql .= " LIMIT 10";
                $deliveryResults = $this->queryExecutor->executeQuery($sql, $p);
                if ($deliveryResults['success']) {
                    $results['deliveries'] = $deliveryResults['data'];
                }
            }
            
            // Search pallets by identifier
            if ($searchType === 'all' || $searchType === 'pallets') {
                $sql = "SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, w.name as warehouse_name,
                              (ip.wattage * ip.quantity) / 1000000 as pallet_mw
                       FROM inventory_pallets ip 
                       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                       LEFT JOIN projects p_assigned ON ip.assigned_project_id = p_assigned.id
                       LEFT JOIN projects p_current ON ip.current_project_id = p_current.id
                       WHERE ip.pallet_identifier LIKE ?";
                $p = ["%{$searchTerm}%"];
                if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
                    $sql .= " AND (p_assigned.account_id = ? OR p_current.account_id = ?)";
                    $p[] = $this->userAccountId;
                    $p[] = $this->userAccountId;
                }
                $sql .= " LIMIT 10";
                $palletResults = $this->queryExecutor->executeQuery($sql, $p);
                if ($palletResults['success']) {
                    $results['pallets'] = $palletResults['data'];
                }
            }
            
            return [
                'success' => true,
                'data' => $results,
                'search_term' => $searchTerm
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Execute a specific tool by name - dispatcher method
     */
    public function executeTool($toolName, $message = '', $userContext = []) {
        try {
            // Normalize tool name for comparison
            $normalizedToolName = strtolower(trim($toolName));

            switch ($normalizedToolName) {
                // Core tools - project and delivery info
                case 'project status':
                case 'projectstatus':
                case 'getprojectsummary':
                case 'project_status':
                    $projectName = $this->extractProjectName($message);
                    return $this->getProjectSummary($projectName);
                    
                case 'inventory summary':
                case 'inventorysummary':
                case 'getwarehouseinventory':
                case 'inventory_summary':
                    $warehouseId = $this->extractWarehouseId($message);
                    return $this->getWarehouseInventory($warehouseId);
                    
                case 'recent deliveries':
                case 'recentdeliveries':
                case 'getdeliverystatus':
                case 'recent_deliveries':
                    return $this->getDeliveryStatus();
                    
                case 'upcoming deliveries':
                case 'upcomingdeliveries':
                case 'getupcomingdeliveries':
                case 'upcoming_deliveries':
                    $projectId = $this->extractProjectId($message);
                    $weeks = $this->extractWeeks($message) ?? 4;
                    return $this->getUpcomingDeliveries($projectId, $weeks);
                    
                // Flash test and POD tools
                case 'getflashtestdata':
                case 'flash_test_data':
                case 'flashtestdata':
                    $projectId = $this->extractProjectId($message);
                    return $this->getFlashTestData($projectId);
                    
                case 'getpodstatus':
                case 'pod_status':
                case 'podstatus':
                    $projectId = $this->extractProjectId($message);
                    if (!$projectId) {
                        $pname = $this->extractProjectName($message);
                        if ($pname) {
                            $res = $this->queryExecutor->executeQuery("SELECT id FROM projects WHERE project_name LIKE ? ORDER BY created_at DESC LIMIT 1", ["%{$pname}%"]);
                            if ($res['success'] && !empty($res['data'])) {
                                $projectId = intval($res['data'][0]['id']);
                            }
                        }
                    }
                    return $this->getPODStatus($projectId);
                    
                // Movement and BOL tools
                case 'getpalletmovements':
                case 'pallet_movements':
                case 'palletmovements':
                    return $this->getPalletMovements();
                    
                case 'getbolinformation':
                case 'bol_information':
                case 'bolinformation':
                    $bolNumber = $this->extractBOLNumber($message);
                    return $this->getBOLInformation($bolNumber);
                    
                // Cost analysis and search
                case 'getprojectcostanalysis':
                case 'project_cost_analysis':
                case 'projectcostanalysis':
                    $projectId = $this->extractProjectId($message);
                    return $this->getProjectCostAnalysis($projectId);
                    
                case 'searchlogistics':
                case 'search_logistics':
                    $searchTerm = $this->extractSearchTerm($message);
                    return $this->searchLogistics($searchTerm);

                // Performance and KPIs
                case 'getdeliveryperformance':
                case 'delivery_performance':
                case 'deliveryperformance':
                case 'performance':
                    $days = $this->extractDays($message) ?? 30;
                    $groupBy = 'manufacturer';
                    $lower = strtolower($message);
                    if (strpos($lower, 'warehouse') !== false) $groupBy = 'warehouse';
                    if (strpos($lower, 'project') !== false) $groupBy = 'project';
                    if (strpos($lower, 'manufacturer') !== false || strpos($lower, 'supplier') !== false) $groupBy = 'manufacturer';
                    return $this->getDeliveryPerformance($days, $groupBy);

                case 'getkpidashboard':
                case 'kpi_dashboard':
                case 'kpidashboard':
                case 'kpi':
                    $days = $this->extractDays($message) ?? 30;
                    return $this->getKPIDashboard($days);

                // Documents
                case 'getprojectdocuments':
                case 'project_documents':
                case 'projectdocuments':
                case 'get_project_documents':
                    $projId = $this->extractProjectId($message);
                    $projName = $projId ? null : $this->extractProjectName($message);
                    $filters = $this->extractDocumentFilters($message);
                    return $this->getProjectDocuments($projId ?: $projName, $filters);

                case 'getglobaldocuments':
                case 'global_documents':
                case 'globaldocuments':
                case 'get_global_documents':
                    $filters = $this->extractDocumentFilters($message);
                    // Allow project name in global context too
                    if (!$filters['project_id'] && !$filters['project_name']) {
                        $pname = $this->extractProjectName($message);
                        if ($pname) $filters['project_name'] = $pname;
                    }
                    return $this->getGlobalDocuments($filters);

                case 'analyzedocument':
                case 'analyze_document':
                    $task = null;
                    if (preg_match('/\b(summary|summarize|review|extract|issues?)\b/i', $message, $m)) {
                        $task = strtolower($m[1]);
                    }
                    return $this->analyzeDocument($task);
                    
                // Memory management tools
                case 'storememory':
                    // Extract parameters from message context
                    $title = $userContext['title'] ?? 'User preference';
                    $content = $userContext['content'] ?? $message;
                    $memoryType = $userContext['memory_type'] ?? 'preference';
                    $category = $userContext['category'] ?? null;
                    $entityId = $userContext['entity_id'] ?? null;
                    $importance = $userContext['importance'] ?? 1;
                    return $this->storeMemory($title, $content, $memoryType, $category, $entityId, $importance);
                    
                case 'getrelevantmemories':
                    $category = $userContext['category'] ?? null;
                    $entityId = $userContext['entity_id'] ?? null;
                    $limit = $userContext['limit'] ?? 10;
                    return $this->getRelevantMemories($category, $entityId, $limit);
                    
                case 'updatememory':
                    $memoryId = $userContext['memory_id'] ?? null;
                    $title = $userContext['title'] ?? null;
                    $content = $userContext['content'] ?? null;
                    $importance = $userContext['importance'] ?? null;
                    return $this->updateMemory($memoryId, $title, $content, $importance);
                    
                case 'deletememory':
                    $memoryId = $userContext['memory_id'] ?? null;
                    return $this->deleteMemory($memoryId);
                    
                default:
                    return [
                        'success' => false,
                        'error' => "Unknown tool: {$toolName} (normalized: {$normalizedToolName})"
                    ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => "Tool execution failed: " . $e->getMessage()
            ];
        }
    }
    
    // Helper methods for extracting information from messages
    private function extractSearchTerm($message) {
        // Normalize quotes and whitespace, keep original case for IDs, but trim punctuation
        $normalized = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);
        if (preg_match('/(?:search|find|lookup)\s+(?:for\s+)?["\']?(.+?)["\']?$/i', $normalized, $matches)) {
            $term = trim($matches[1]);
            return rtrim($term, ",.!? ");
        }
        return rtrim(trim($normalized), ",.!? ");
    }

    /**
     * Get documents for a specific project or across account (global)
     * Supports simple filters inferred from message.
     */
    public function getProjectDocuments($projectSpecifier = null, $filters = []) {
        // Resolve project ID by name or number
        $projectId = null;
        if (is_numeric($projectSpecifier)) {
            $projectId = intval($projectSpecifier);
        } elseif (is_string($projectSpecifier) && $projectSpecifier !== '') {
            $sql = "SELECT id FROM projects WHERE project_name LIKE ? ORDER BY created_at DESC LIMIT 1";
            $res = $this->queryExecutor->executeQuery($sql, ["%{$projectSpecifier}%"]);
            if ($res['success'] && !empty($res['data'])) {
                $projectId = intval($res['data'][0]['id']);
            }
        }
        if (!$projectId) {
            // Best-effort: if not specified, return empty success to let model ask for clarification
            return ['success' => true, 'data' => [], 'error' => null, 'note' => 'No project specified'];
        }

        // Build query (subset of get_global_documents.php logic)
        $sql = "
            SELECT 
                pd.id,
                pd.original_file_name as filename,
                pd.file_size,
                pd.mime_type,
                pd.uploaded_at,
                pd.description,
                pd.file_path,
                pd.document_type,
                pd.document_sub_type,
                pd.delivery_id,
                d.bol_number,
                d.supplier as supplier_name,
                p.project_name
            FROM project_documents pd
            JOIN projects p ON pd.project_id = p.id
            LEFT JOIN deliveries d ON pd.delivery_id = d.id
            WHERE pd.project_id = ? AND pd.is_active = 1
        ";
        $params = [$projectId];

        // Apply lightweight filters
        if (!empty($filters['document_type'])) {
            $sql .= " AND pd.document_type = ?";
            $params[] = $filters['document_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (pd.original_file_name LIKE ? OR pd.description LIKE ?)";
            $like = '%' . $filters['search'] . '%';
            $params[] = $like; $params[] = $like;
        }
        if (!empty($filters['bol'])) {
            $sql .= " AND d.bol_number LIKE ?";
            $params[] = '%' . $filters['bol'] . '%';
        }
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(pd.uploaded_at) >= ?";
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(pd.uploaded_at) <= ?";
            $params[] = $filters['end_date'];
        }
        // Tenant scoping
        if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
            $sql .= " AND p.account_id = ?";
            $params[] = $this->userAccountId;
        }

        $sql .= " ORDER BY pd.uploaded_at DESC LIMIT 100";

        $result = $this->queryExecutor->executeQuery($sql, $params);
        if (!$result['success']) return $result;

        // Normalize with download/deep links
        $docs = array_map(function($row) use ($projectId, $filters) {
            $downloadUrl = 'download_document.php?id=' . $row['id'];
            $projectPageUrl = 'project_documents.php?project_id=' . $projectId;
            $globalPageUrl = 'global_documents.php?project_id=' . $projectId;
            if (!empty($filters['document_type'])) {
                $projectPageUrl .= '&document_type=' . urlencode($filters['document_type']);
                $globalPageUrl  .= '&document_type=' . urlencode($filters['document_type']);
            }
            if (!empty($filters['search'])) {
                $projectPageUrl .= '&search=' . urlencode($filters['search']);
                $globalPageUrl  .= '&search=' . urlencode($filters['search']);
            }
            if (!empty($filters['start_date'])) {
                $projectPageUrl .= '&start_date=' . urlencode($filters['start_date']);
                $globalPageUrl  .= '&start_date=' . urlencode($filters['start_date']);
            }
            if (!empty($filters['end_date'])) {
                $projectPageUrl .= '&end_date=' . urlencode($filters['end_date']);
                $globalPageUrl  .= '&end_date=' . urlencode($filters['end_date']);
            }
            return [
                'id' => intval($row['id']),
                'filename' => $row['filename'],
                'uploaded_at' => $row['uploaded_at'],
                'document_type' => $row['document_type'],
                'document_sub_type' => $row['document_sub_type'],
                'project_name' => $row['project_name'],
                'bol_number' => $row['bol_number'],
                'supplier' => $row['supplier_name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => intval($row['file_size'] ?? 0),
                'url_download' => $downloadUrl,
                'link_project_page' => $projectPageUrl,
                'link_global_page' => $globalPageUrl
            ];
        }, $result['data']);

        return ['success' => true, 'data' => $docs];
    }

    public function getGlobalDocuments($filters = []) {
        // Base query across account projects (role-based filter is injected by QueryExecutor)
        $sql = "
            SELECT 
                pd.id,
                pd.original_file_name as filename,
                pd.file_size,
                pd.mime_type,
                pd.uploaded_at,
                pd.description,
                pd.file_path,
                pd.document_type,
                pd.document_sub_type,
                pd.project_id,
                p.project_name,
                d.bol_number,
                d.supplier as supplier_name
            FROM project_documents pd
            JOIN projects p ON pd.project_id = p.id
            LEFT JOIN deliveries d ON pd.delivery_id = d.id
            WHERE pd.is_active = 1
        ";
        $params = [];

        if (!empty($filters['project_id'])) {
            $sql .= " AND pd.project_id = ?";
            $params[] = intval($filters['project_id']);
        }
        if (!empty($filters['project_name'])) {
            $sql .= " AND p.project_name LIKE ?";
            $params[] = '%' . $filters['project_name'] . '%';
        }
        if (!empty($filters['document_type'])) {
            $sql .= " AND pd.document_type = ?";
            $params[] = $filters['document_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (pd.original_file_name LIKE ? OR pd.description LIKE ?)";
            $like = '%' . $filters['search'] . '%';
            $params[] = $like; $params[] = $like;
        }
        if (!empty($filters['bol'])) {
            $sql .= " AND d.bol_number LIKE ?";
            $params[] = '%' . $filters['bol'] . '%';
        }
        if (!empty($filters['start_date'])) {
            $sql .= " AND DATE(pd.uploaded_at) >= ?";
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND DATE(pd.uploaded_at) <= ?";
            $params[] = $filters['end_date'];
        }
        // Tenant scoping
        if ($this->userAccountId !== null && $this->userRole !== 'global_admin') {
            $sql .= " AND p.account_id = ?";
            $params[] = $this->userAccountId;
        }

        $sql .= " ORDER BY pd.uploaded_at DESC LIMIT 100";

        $result = $this->queryExecutor->executeQuery($sql, $params);
        if (!$result['success']) return $result;

        $docs = array_map(function($row) use ($filters) {
            $downloadUrl = 'download_document.php?id=' . $row['id'];
            $globalPageUrl = 'global_documents.php';
            $pp = [];
            foreach (['project_id','document_type','search','start_date','end_date'] as $k) {
                if (!empty($filters[$k])) $pp[$k] = $filters[$k];
            }
            if (!empty($pp)) {
                $globalPageUrl .= '?' . http_build_query($pp);
            }

            return [
                'id' => intval($row['id']),
                'filename' => $row['filename'],
                'uploaded_at' => $row['uploaded_at'],
                'document_type' => $row['document_type'],
                'document_sub_type' => $row['document_sub_type'],
                'project_id' => intval($row['project_id'] ?? 0),
                'project_name' => $row['project_name'],
                'bol_number' => $row['bol_number'],
                'supplier' => $row['supplier_name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => intval($row['file_size'] ?? 0),
                'url_download' => $downloadUrl,
                'link_global_page' => $globalPageUrl
            ];
        }, $result['data']);

        return ['success' => true, 'data' => $docs];
    }
    
    private function extractProjectName($message) {
        $original = $message;
        // Normalize fancy quotes to straight quotes
        $message = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);

        // Helper to ignore generic descriptors like "status" that can appear in quick actions
        $isGenericWord = function($name) {
            $generic = ['status','summary','overview','info','information'];
            return in_array(strtolower(trim($name)), $generic, true);
        };

        // 1) Try explicit quoted name after the word project (named/called optional)
        if (preg_match('/project\s*(?:named|called|=)?\s*["\']([^"\']+)["\']/i', $message, $m)) {
            $name = trim($m[1]);
            if ($name === '' || $isGenericWord($name)) { return null; }
            return $name;
        }

        // 2) Try label-style: project: <name>
        if (preg_match('/project\s*[:\-]\s*([A-Za-z0-9][A-Za-z0-9 .&_()\-]{0,100})/i', $message, $m)) {
            $name = trim($m[1]);
            $name = rtrim($name, ".,!?\s");
            if ($name === '' || $isGenericWord($name)) { return null; }
            return $name;
        }

        // 3) Try simple: about the project <name>
        if (preg_match('/project\s+([A-Za-z0-9][A-Za-z0-9 .&_()\-]{0,100})/i', $message, $m)) {
            $name = trim($m[1]);
            // Stop at sentence-ending punctuation
            $name = preg_split('/[.!?,]/', $name)[0];
            $name = trim($name);
            if ($name === '' || $isGenericWord($name)) { return null; }
            return $name;
        }

        // 4) Handle trailing pattern: <name> Project (e.g., "Morgan Test Project")
        if (preg_match('/\b([A-Za-z0-9][A-Za-z0-9 .&_()\-]{1,100})\s+project\b/i', $message, $m)) {
            $name = trim($m[1]);
            $name = rtrim($name, ".,!?\s");
            if ($name === '' || $isGenericWord($name)) { return null; }
            return $name;
        }

        // 5) Handle "for <name> project" phrasing
        if (preg_match('/\bfor\s+([A-Za-z0-9][A-Za-z0-9 .&_()\-]{1,100})\s+project\b/i', $message, $m)) {
            $name = trim($m[1]);
            $name = rtrim($name, ".,!?\s");
            if ($name === '' || $isGenericWord($name)) { return null; }
            return $name;
        }

        return null;
    }
    
    private function extractProjectId($message) {
        $normalized = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);
        if (preg_match('/project\s+id[:\s]*["\']?(\d+)["\']?/i', $normalized, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }
    
    private function extractWarehouseId($message) {
        $normalized = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);
        if (preg_match('/warehouse\s+(?:id[:\s]*)?["\']?(\d+)["\']?/i', $normalized, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }
    
    private function extractBOLNumber($message) {
        $normalized = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);
        // Allow optional quotes and common separators after BOL label
        if (preg_match('/\bbol\b\s*[:#-]?\s*["\']?([a-z0-9\-]+)["\']?/i', $normalized, $matches)) {
            return rtrim(trim($matches[1]), ",.!? ");
        }
        return null;
    }
    
    private function extractWeeks($message) {
        $normalized = str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message);
        if (preg_match('/["\']?(\d+)["\']?\s*weeks?/i', $normalized, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    private function extractDays($message) {
        $normalized = strtolower(str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message));
        // explicit: "last 14 days" or "past 14 days"
        if (preg_match('/(?:last|past)\s+(\d+)\s*days?/i', $normalized, $m)) {
            return intval($m[1]);
        }
        // explicit weeks -> days
        if (preg_match('/(?:last|past)\s+(\d+)\s*weeks?/i', $normalized, $m)) {
            return intval($m[1]) * 7;
        }
        // explicit months -> days (approx)
        if (preg_match('/(?:last|past)\s+(\d+)\s*months?/i', $normalized, $m)) {
            return intval($m[1]) * 30;
        }
        return null;
    }

    /**
     * Analyze most recent uploaded document (session) and extract text for the model
     */
    public function analyzeDocument($task = null) {
        try {
            if (session_status() === PHP_SESSION_NONE) { session_name('logistics_session'); session_start(); }
            $uploads = $_SESSION['sunny_uploads'] ?? [];
            $lastId = $_SESSION['sunny_last_upload'] ?? null;
            if (!$lastId || !isset($uploads[$lastId])) {
                return ['success' => false, 'error' => 'No uploaded file found in this session'];
            }
            $info = $uploads[$lastId];
            $path = $info['path'];
            $mime = $info['mime'];
            if (!is_readable($path)) {
                return ['success' => false, 'error' => 'Uploaded file is not accessible'];
            }

            $content = $this->extractTextFromFile($path, $mime);
            if ($content === null) {
                return ['success' => false, 'error' => 'Unable to extract text from file'];
            }
            $preview = mb_substr($content, 0, 12000);
            return [
                'success' => true,
                'data' => [
                    'filename' => $info['filename'],
                    'mime' => $mime,
                    'size' => $info['size'],
                    'task' => $task,
                    'text_preview' => $preview,
                    'note' => 'Preview truncated to ~12k chars. Ask for specific sections if needed.'
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function extractTextFromFile($path, $mime) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($mime, ['text/plain', 'text/csv'])) {
            return @file_get_contents($path) ?: '';
        }
        // DOCX support
        if ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $ext === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $index = $zip->locateName('word/document.xml');
                if ($index !== false) {
                    $xml = $zip->getFromIndex($index);
                    $zip->close();
                    return html_entity_decode(strip_tags($xml));
                }
                $zip->close();
            }
            return null;
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                if (class_exists('Smalot\\PdfParser\\Parser')) {
                    try {
                        $parser = new Smalot\PdfParser\Parser();
                        $pdf = $parser->parseFile($path);
                        return $pdf->getText();
                    } catch (Exception $e) {
                        // ignore and fall through
                    }
                }
            }
            // Fallback: simple text extraction for basic PDFs
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $simple = $this->simplePdfExtract($raw);
                if ($simple && trim($simple) !== '') return $simple;
            }
            return null;
        }
        return null;
    }

    private function simplePdfExtract($content) {
        $text = '';
        $content = str_replace("\0", '', $content);
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m)) {
            foreach ($m[1] as $arr) {
                if (preg_match_all('/\((.*?)\)/s', $arr, $s)) {
                    foreach ($s[1] as $seg) { $text .= $this->pdfDecode($seg); }
                    $text .= "\n";
                }
            }
        }
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $content, $m2)) {
            foreach ($m2[1] as $seg) { $text .= $this->pdfDecode($seg) . "\n"; }
        }
        return trim($text);
    }

    private function pdfDecode($s) {
        $s = str_replace(['\\n','\\r','\\t','\\b','\\f','\\(','\\)','\\\\'], ["\n","\r","\t","\x08","\f","(",")","\\"], $s);
        return $s;
    }

    // Extract basic document filters from a message (type, search term, bol, dates)
    private function extractDocumentFilters($message) {
        $filters = [
            'document_type' => null,
            'search' => null,
            'bol' => null,
            'start_date' => null,
            'end_date' => null,
            'project_id' => null,
            'project_name' => null,
        ];

        $normalized = strtolower(str_replace(["“","”","‘","’"], ['"','"','\'','\''], $message));

        // Map common keywords to document_type used in project_documents
        $typeMap = [
            'pod' => 'pods',
            'pods' => 'pods',
            'invoice' => 'invoices',
            'invoices' => 'invoices',
            'shipment' => 'shipments',
            'shipments' => 'shipments',
            'warehous' => 'warehousing',
            'exception' => 'exception_reports',
            'flash test' => 'modules',
            'spec sheet' => 'modules',
            'safe harbor' => 'safe_harbor_evidence'
        ];
        foreach ($typeMap as $k => $val) {
            if (strpos($normalized, $k) !== false) {
                $filters['document_type'] = $val;
                break;
            }
        }

        // Extract BOL number if present
        $bol = $this->extractBOLNumber($message);
        if ($bol) $filters['bol'] = $bol;

        // Extract quoted search term if present
        if (preg_match('/["\']([^"\']{2,})["\']/', $message, $m)) {
            $filters['search'] = rtrim(trim($m[1]), ",.!? ");
        }

        // Simple date range: phrases like from 2025-06-01 to 2025-06-30
        if (preg_match('/from\s+(\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})/i', $message, $m)) {
            $filters['start_date'] = $m[1];
            $filters['end_date'] = $m[2];
        }

        // Project id if explicitly given
        $pid = $this->extractProjectId($message);
        if ($pid) $filters['project_id'] = $pid;

        return $filters;
    }

    /**
     * Execute custom SQL query (with safety checks)
     */
    public function executeCustomQuery($sql, $params = []) {
        return $this->queryExecutor->executeQuery($sql, $params);
    }
    
    /**
     * Get table summary
     */
    public function getTableSummary($tableName) {
        return $this->queryExecutor->getTableSummary($tableName);
    }
    
    /**
     * Close resources
     */
    public function close() {
        if ($this->queryExecutor) {
            $this->queryExecutor->close();
        }
    }
    
    /**
     * Memory Management Methods
     */
    
    /**
     * Store a memory for the current user
     */
    public function storeMemory($title, $content, $memoryType = 'note', $category = null, $entityId = null, $importance = 1) {
        try {
            $sql = "INSERT INTO sunny_memory (user_id, account_id, memory_type, category, entity_id, title, content, importance) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $this->getUserId(),
                $this->userAccountId,
                $memoryType,
                $category,
                $entityId,
                $title,
                $content,
                $importance
            ];
            
            // Use direct connection since QueryExecutor blocks INSERTs
            require_once dirname(__DIR__, 3) . '/config.php';
            $conn = getDBConnection();
            if (!$conn) {
                throw new Exception('Database connection failed');
            }

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }

            // Bind parameters dynamically
            $stmt->bind_param(
                "iississi",
                $params[0], // user_id
                $params[1], // account_id
                $params[2], // memory_type
                $params[3], // category
                $params[4], // entity_id
                $params[5], // title
                $params[6], // content
                $params[7]  // importance
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                $conn->close();
                throw new Exception('Insert failed: ' . $err);
            }
            $stmt->close();
            $conn->close();

            return ['success' => true, 'insert_id' => $conn->insert_id ?? null];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Retrieve memories for context injection
     */
    public function getRelevantMemories($category = null, $entityId = null, $limit = 10) {
        try {
            $sql = "SELECT id, title, content, memory_type, category, entity_id, importance, created_at
                    FROM sunny_memory 
                    WHERE user_id = ? AND (account_id = ? OR account_id IS NULL)";
            
            $params = [$this->getUserId(), $this->userAccountId];
            
            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            if ($entityId) {
                $sql .= " AND entity_id = ?";
                $params[] = $entityId;
            }
            
            $sql .= " ORDER BY importance DESC, created_at DESC LIMIT ?";
            $params[] = $limit;
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update existing memory
     */
    public function updateMemory($memoryId, $title = null, $content = null, $importance = null) {
        try {
            $updates = [];
            $params = [];
            
            if ($title !== null) {
                $updates[] = "title = ?";
                $params[] = $title;
            }
            
            if ($content !== null) {
                $updates[] = "content = ?";
                $params[] = $content;
            }
            
            if ($importance !== null) {
                $updates[] = "importance = ?";
                $params[] = $importance;
            }
            
            if (empty($updates)) {
                return ['success' => false, 'error' => 'No updates provided'];
            }
            
            $sql = "UPDATE sunny_memory SET " . implode(', ', $updates) . " WHERE id = ? AND user_id = ?";
            $params[] = $memoryId;
            $params[] = $this->getUserId();

            // Use direct connection for write
            require_once dirname(__DIR__, 3) . '/config.php';
            $conn = getDBConnection();
            if (!$conn) {
                throw new Exception('Database connection failed');
            }
            $placeholders = str_repeat('s', count($params));
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param($placeholders, ...$params);
            $ok = $stmt->execute();
            if (!$ok) {
                $err = $stmt->error;
                $stmt->close();
                $conn->close();
                throw new Exception('Update failed: ' . $err);
            }
            $stmt->close();
            $conn->close();

            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete a memory
     */
    public function deleteMemory($memoryId) {
        try {
            $sql = "DELETE FROM sunny_memory WHERE id = ? AND user_id = ?";
            $params = [$memoryId, $this->getUserId()];

            // Use direct connection for write
            require_once dirname(__DIR__, 3) . '/config.php';
            $conn = getDBConnection();
            if (!$conn) {
                throw new Exception('Database connection failed');
            }
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('ii', $params[0], $params[1]);
            $ok = $stmt->execute();
            if (!$ok) {
                $err = $stmt->error;
                $stmt->close();
                $conn->close();
                throw new Exception('Delete failed: ' . $err);
            }
            $stmt->close();
            $conn->close();

            return ['success' => true];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user ID from session or context
     */
    private function getUserId() {
        return $_SESSION['user_id'] ?? 1; // Fallback for testing
    }
    
    public function __destruct() {
        $this->close();
    }
} 
