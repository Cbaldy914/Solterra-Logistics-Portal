<?php
/**
 * Sunny AI Tools
 * Provides pre-built functions for common logistics queries
 * Updated to match actual database schema focusing on PODs, BOLs, Flash Test Data, inventory, and project status
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
                    $storedSize = floatval($project['stored_project_size']);
                    
                    // Always try to calculate from wattage orders first, since stored size might be unreliable
                    $sizeSql = "SELECT SUM(CAST(wattage AS UNSIGNED) * total_order) / 1000000 as calculated_size FROM project_wattage_orders WHERE project_id = ?";
                    $sizeResult = $this->queryExecutor->executeQuery($sizeSql, [$projectId]);
                    
                    if ($sizeResult['success'] && !empty($sizeResult['data']) && $sizeResult['data'][0]['calculated_size'] > 0) {
                        $project['project_size_mw'] = floatval($sizeResult['data'][0]['calculated_size']);
                    } else {
                        $project['project_size_mw'] = $storedSize;
                    }
                    
                    $deliveredMW = floatval($project['total_delivered_mw']);
                    $storageMW = floatval($project['mw_in_storage']);
                    
                    // Calculate remaining MW
                    $project['remaining_mw'] = round($project['project_size_mw'] - $deliveredMW, 3);
                    
                    // Format storage as single field (only show if > 0)
                    if ($storageMW > 0) {
                        $project['mw_in_storage'] = round($storageMW, 3) . ' MW';
                    } else {
                        $project['mw_in_storage'] = null; // Don't show storage if 0
                    }
                    
                    // Clean up the stored project size field
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
     */
    public function getDeliveryStatus($projectId = null, $status = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.bol_number,
                    d.supplier,
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
                    w.name as warehouse_name
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
                    w.in_fee,
                    w.out_fee,
                    w.monthly_storage_fee,
                    -- Current inventory metrics
                    COUNT(CASE WHEN ip.status = 'In Warehouse' THEN ip.id END) as pallets_in_storage,
                    SUM(CASE WHEN ip.status = 'In Warehouse' THEN ip.quantity ELSE 0 END) as modules_in_storage,
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
                    
                    // Calculate estimated costs based on warehouse_info.php logic
                    $palletsInStorage = intval($warehouse['pallets_in_storage'] ?? 0);
                    $avgDaysStored = floatval($warehouse['avg_days_stored'] ?? 0);
                    $monthlyStorageFee = floatval($warehouse['monthly_storage_fee'] ?? 0);
                    $inFee = floatval($warehouse['in_fee'] ?? 0);
                    $outFee = floatval($warehouse['out_fee'] ?? 0);
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
                    
                    // Get top projects by module count
                    $projectSql = "
                        SELECT 
                            p.project_name,
                            COUNT(ip.id) as pallet_count,
                            SUM(ip.quantity) as module_count
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
                    if ($modulesInStorage > 0) {
                        $warehouse['inventory_summary'] = "{$palletsInStorage} pallets, {$modulesInStorage} modules in storage";
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
                    p_current.project_name as current_project
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
     */
    public function getBOLInformation($bolNumber = null, $days = 60) {
        try {
            $sql = "
                SELECT 
                    d.id as delivery_id,
                    d.bol_number,
                    d.supplier,
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
                    ss.bol_file
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
     */
    public function getPODStatus($projectId = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.bol_number,
                    d.supplier,
                    d.proof_of_delivery,
                    d.actual_delivery_date,
                    d.status_of_delivery,
                    p.project_name,
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
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
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
                    SUM(ap.amount) as total_payables
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
     * Get delivery performance metrics
     */
    public function getDeliveryPerformance($days = 90) {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_deliveries,
                    SUM(CASE WHEN actual_delivery_date IS NOT NULL THEN 1 ELSE 0 END) as completed_deliveries,
                    SUM(CASE WHEN actual_delivery_date IS NULL AND anticipated_delivery_date < CURDATE() THEN 1 ELSE 0 END) as overdue_deliveries,
                    SUM(CASE WHEN proof_of_delivery IS NOT NULL THEN 1 ELSE 0 END) as deliveries_with_pod,
                    AVG(CASE 
                        WHEN actual_delivery_date IS NOT NULL AND anticipated_delivery_date IS NOT NULL 
                        THEN DATEDIFF(actual_delivery_date, anticipated_delivery_date) 
                        ELSE NULL 
                    END) as avg_delay_days,
                    supplier,
                    AVG(freight_cost) as avg_freight_cost
                FROM deliveries 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY supplier
                ORDER BY total_deliveries DESC
            ";
            
            return $this->queryExecutor->executeQuery($sql, [$days]);
            
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
            
            // Search deliveries by BOL number
            if ($searchType === 'all' || $searchType === 'deliveries') {
                $sql = "SELECT d.id, d.bol_number, d.supplier, d.status_of_delivery, p.project_name 
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
                $sql = "SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status, w.name as warehouse_name
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
     * Get key performance indicators dashboard
     */
    public function getKPIDashboard() {
        try {
            $kpis = [];
            
            // Total active projects
            $projectsResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM projects");
            $kpis['total_projects'] = $projectsResult['data'][0]['count'] ?? 0;
            
            // Pending deliveries
            $deliveriesResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM deliveries WHERE actual_delivery_date IS NULL");
            $kpis['pending_deliveries'] = $deliveriesResult['data'][0]['count'] ?? 0;
            
            // Pallets in storage
            $palletsResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM inventory_pallets WHERE status = 'In Warehouse'");
            $kpis['pallets_in_storage'] = $palletsResult['data'][0]['count'] ?? 0;
            
            // This month's completed deliveries
            $completedResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM deliveries WHERE actual_delivery_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $kpis['recent_deliveries'] = $completedResult['data'][0]['count'] ?? 0;
            
            // Missing PODs
            $podResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM deliveries WHERE actual_delivery_date IS NOT NULL AND proof_of_delivery IS NULL");
            $kpis['missing_pods'] = $podResult['data'][0]['count'] ?? 0;
            
            return [
                'success' => true,
                'data' => $kpis
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
                // Quick action mappings - try multiple variations
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
                    
                case 'getflashtestdata':
                    $projectId = $this->extractProjectId($message);
                    return $this->getFlashTestData($projectId);
                    
                case 'getpalletmovements':
                    return $this->getPalletMovements();
                    
                case 'getbolinformation':
                    $bolNumber = $this->extractBOLNumber($message);
                    return $this->getBOLInformation($bolNumber);
                    
                case 'getpodstatus':
                    $projectId = $this->extractProjectId($message);
                    return $this->getPODStatus($projectId);
                    
                case 'getprojectcostanalysis':
                    $projectId = $this->extractProjectId($message);
                    return $this->getProjectCostAnalysis($projectId);
                    
                case 'getdeliveryperformance':
                    return $this->getDeliveryPerformance();
                    
                case 'searchlogistics':
                    $searchTerm = $this->extractSearchTerm($message);
                    return $this->searchLogistics($searchTerm);
                    
                case 'getkpidashboard':
                    return $this->getKPIDashboard();
                    
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
    
    public function __destruct() {
        $this->close();
    }
} 