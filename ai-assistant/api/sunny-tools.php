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
            if ($projectName) {
                $sql .= " WHERE p.project_name LIKE ?";
                $params[] = "%{$projectName}%";
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
            // Get warehouse basic info with inventory summary
            $sql = "
                SELECT 
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    w.address,
                    w.city,
                    w.state,
                    -- Current inventory metrics
                    COUNT(CASE WHEN ip.status = 'In Warehouse' THEN ip.id END) as pallets_in_storage,
                    SUM(CASE WHEN ip.status = 'In Warehouse' THEN ip.quantity ELSE 0 END) as modules_in_storage,
                    SUM(CASE WHEN ip.status = 'In Warehouse' THEN (ip.wattage * ip.quantity) ELSE 0 END) / 1000000 as mw_in_storage,
                    -- Average days stored for current inventory
                    AVG(CASE WHEN ip.status = 'In Warehouse' AND ip.arrival_date IS NOT NULL 
                        THEN DATEDIFF(CURDATE(), ip.arrival_date) ELSE NULL END) as avg_days_stored,
                    -- Available wattages
                    GROUP_CONCAT(DISTINCT CASE WHEN ip.status = 'In Warehouse' THEN ip.wattage END ORDER BY ip.wattage) as wattages_available,
                    -- Project breakdown
                    COUNT(DISTINCT CASE WHEN ip.status = 'In Warehouse' AND ip.assigned_project_id IS NOT NULL THEN ip.assigned_project_id END) as projects_with_inventory
                FROM warehouses w
                LEFT JOIN inventory_pallets ip ON w.id = ip.current_warehouse_id
            ";
            
            $params = [];
            if ($warehouseId) {
                $sql .= " WHERE w.id = ?";
                $params[] = $warehouseId;
            }
            
            $sql .= " GROUP BY w.id ORDER BY w.name";
            
            $result = $this->queryExecutor->executeQuery($sql, $params);
            
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as &$warehouse) {
                    $warehouseId = $warehouse['warehouse_id'];
                    
                    // Get delivery activity (inbound/outbound counts from last 30 days)
                    $deliverySql = "
                        SELECT 
                            COUNT(CASE WHEN warehouse_arrival_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_inbound,
                            COUNT(CASE WHEN left_warehouse_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_outbound,
                            COUNT(CASE WHEN warehouse_arrival_date IS NOT NULL THEN 1 END) as total_inbound,
                            COUNT(CASE WHEN left_warehouse_date IS NOT NULL THEN 1 END) as total_outbound
                        FROM deliveries 
                        WHERE warehouse_id = ?
                    ";
                    
                    $deliveryResult = $this->queryExecutor->executeQuery($deliverySql, [$warehouseId]);
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
                        WHERE ip.current_warehouse_id = ? AND ip.status = 'In Warehouse'
                        GROUP BY p.id, p.project_name
                        ORDER BY module_count DESC
                        LIMIT 5
                    ";
                    
                    $projectResult = $this->queryExecutor->executeQuery($projectSql, [$warehouseId]);
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
     * Search for specific information across multiple tables
     */
    public function searchLogistics($searchTerm, $searchType = 'all') {
        try {
            $results = [];
            
            // Search projects
            if ($searchType === 'all' || $searchType === 'projects') {
                $sql = "SELECT id, project_name, project_address, city, state FROM projects WHERE project_name LIKE ? LIMIT 10";
                $projectResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%"]);
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
                       WHERE d.bol_number LIKE ? OR d.supplier LIKE ? LIMIT 10";
                $deliveryResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%", "%{$searchTerm}%"]);
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
                       WHERE ip.pallet_identifier LIKE ? LIMIT 10";
                $palletResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%"]);
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
        $message = strtolower($message);
        if (preg_match('/(?:search|find|lookup)\s+(?:for\s+)?(.+)/', $message, $matches)) {
            return trim($matches[1]);
        }
        return $message;
    }
    
    private function extractProjectName($message) {
        if (preg_match('/project[:\s]+([^\s,]+)/i', $message, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    private function extractProjectId($message) {
        if (preg_match('/project\s+id[:\s]*(\d+)/i', $message, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }
    
    private function extractWarehouseId($message) {
        if (preg_match('/warehouse\s+(?:id[:\s]*)?(\d+)/i', $message, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }
    
    private function extractBOLNumber($message) {
        if (preg_match('/bol[:\s#]*([a-z0-9\-]+)/i', $message, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
    
    private function extractWeeks($message) {
        if (preg_match('/(\d+)\s*weeks?/i', $message, $matches)) {
            return intval($matches[1]);
        }
        return null;
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
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
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
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
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