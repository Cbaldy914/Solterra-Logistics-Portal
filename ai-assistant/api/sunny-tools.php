<?php
/**
 * Sunny AI Tools
 * Provides pre-built functions for common logistics queries
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
     * Get project summary with module counts and delivery status
     */
    public function getProjectSummary($projectName = null, $limit = 10) {
        try {
            $sql = "
                SELECT 
                    p.id,
                    p.project_name,
                    p.status,
                    p.estimated_start_date,
                    p.actual_start_date,
                    COUNT(DISTINCT m.id) as total_modules,
                    COUNT(DISTINCT d.id) as total_deliveries,
                    SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
                    p.created_at
                FROM projects p
                LEFT JOIN modules m ON p.id = m.project_id
                LEFT JOIN deliveries d ON p.id = d.project_id
            ";
            
            $params = [];
            if ($projectName) {
                $sql .= " WHERE p.project_name LIKE ?";
                $params[] = "%{$projectName}%";
            }
            
            $sql .= " GROUP BY p.id ORDER BY p.created_at DESC LIMIT {$limit}";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get delivery status and tracking information
     */
    public function getDeliveryStatus($projectId = null, $status = null, $limit = 20) {
        try {
            $sql = "
                SELECT 
                    d.id,
                    d.delivery_id,
                    d.status,
                    d.scheduled_delivery_date,
                    d.actual_delivery_date,
                    d.carrier,
                    d.tracking_number,
                    p.project_name,
                    w.warehouse_name,
                    COUNT(dp.pallet_id) as pallet_count
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                LEFT JOIN warehouses w ON d.warehouse_id = w.id
                LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
            ";
            
            $whereConditions = [];
            $params = [];
            
            if ($projectId) {
                $whereConditions[] = "d.project_id = ?";
                $params[] = $projectId;
            }
            
            if ($status) {
                $whereConditions[] = "d.status = ?";
                $params[] = $status;
            }
            
            if (!empty($whereConditions)) {
                $sql .= " WHERE " . implode(" AND ", $whereConditions);
            }
            
            $sql .= " GROUP BY d.id ORDER BY d.scheduled_delivery_date DESC LIMIT {$limit}";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get warehouse inventory summary
     */
    public function getWarehouseInventory($warehouseId = null) {
        try {
            $sql = "
                SELECT 
                    w.id as warehouse_id,
                    w.warehouse_name,
                    w.address,
                    COUNT(ip.id) as total_pallets,
                    SUM(CASE WHEN ip.status = 'in_storage' THEN 1 ELSE 0 END) as pallets_in_storage,
                    SUM(CASE WHEN ip.status = 'shipped' THEN 1 ELSE 0 END) as pallets_shipped,
                    SUM(ip.monthly_storage_cost) as total_monthly_cost
                FROM warehouses w
                LEFT JOIN inventory_pallets ip ON w.id = ip.warehouse_id
            ";
            
            $params = [];
            if ($warehouseId) {
                $sql .= " WHERE w.id = ?";
                $params[] = $warehouseId;
            }
            
            $sql .= " GROUP BY w.id ORDER BY w.warehouse_name";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get recent module movements
     */
    public function getModuleMovements($projectId = null, $days = 30) {
        try {
            $sql = "
                SELECT 
                    m.id,
                    m.vendor_name,
                    m.model_number,
                    m.wattage,
                    m.quantity,
                    m.initial_location,
                    m.current_location,
                    p.project_name,
                    m.updated_at
                FROM modules m
                LEFT JOIN projects p ON m.project_id = p.id
                WHERE m.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            
            $params = [$days];
            
            if ($projectId) {
                $sql .= " AND m.project_id = ?";
                $params[] = $projectId;
            }
            
            $sql .= " ORDER BY m.updated_at DESC LIMIT 50";
            
            return $this->queryExecutor->executeQuery($sql, $params);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get project cost analysis
     */
    public function getProjectCostAnalysis($projectId = null) {
        try {
            $sql = "
                SELECT 
                    p.id,
                    p.project_name,
                    COUNT(DISTINCT m.id) as module_batches,
                    SUM(m.quantity * m.cost_per_module) as total_module_cost,
                    SUM(CASE WHEN d.id IS NOT NULL THEN d.shipping_cost ELSE 0 END) as total_shipping_cost,
                    AVG(m.cost_per_module) as avg_cost_per_module
                FROM projects p
                LEFT JOIN modules m ON p.id = m.project_id
                LEFT JOIN deliveries d ON p.id = d.project_id
            ";
            
            $params = [];
            if ($projectId) {
                $sql .= " WHERE p.id = ?";
                $params[] = $projectId;
            }
            
            $sql .= " GROUP BY p.id ORDER BY total_module_cost DESC LIMIT 20";
            
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
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_deliveries,
                    SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) as delayed_deliveries,
                    AVG(CASE 
                        WHEN actual_delivery_date IS NOT NULL AND scheduled_delivery_date IS NOT NULL 
                        THEN DATEDIFF(actual_delivery_date, scheduled_delivery_date) 
                        ELSE NULL 
                    END) as avg_delay_days,
                    carrier
                FROM deliveries 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY carrier
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
                $sql = "SELECT id, project_name, status FROM projects WHERE project_name LIKE ? LIMIT 10";
                $projectResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%"]);
                if ($projectResults['success']) {
                    $results['projects'] = $projectResults['data'];
                }
            }
            
            // Search deliveries by tracking number
            if ($searchType === 'all' || $searchType === 'deliveries') {
                $sql = "SELECT delivery_id, tracking_number, status, carrier FROM deliveries WHERE tracking_number LIKE ? OR delivery_id LIKE ? LIMIT 10";
                $deliveryResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%", "%{$searchTerm}%"]);
                if ($deliveryResults['success']) {
                    $results['deliveries'] = $deliveryResults['data'];
                }
            }
            
            // Search modules by vendor or model
            if ($searchType === 'all' || $searchType === 'modules') {
                $sql = "SELECT vendor_name, model_number, wattage, quantity FROM modules WHERE vendor_name LIKE ? OR model_number LIKE ? LIMIT 10";
                $moduleResults = $this->queryExecutor->executeQuery($sql, ["%{$searchTerm}%", "%{$searchTerm}%"]);
                if ($moduleResults['success']) {
                    $results['modules'] = $moduleResults['data'];
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
            $projectsResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
            $kpis['active_projects'] = $projectsResult['data'][0]['count'] ?? 0;
            
            // Pending deliveries
            $deliveriesResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM deliveries WHERE status = 'in_transit'");
            $kpis['pending_deliveries'] = $deliveriesResult['data'][0]['count'] ?? 0;
            
            // Modules in storage
            $modulesResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM inventory_pallets WHERE status = 'in_storage'");
            $kpis['modules_in_storage'] = $modulesResult['data'][0]['count'] ?? 0;
            
            // This month's completed deliveries
            $completedResult = $this->queryExecutor->executeQuery("SELECT COUNT(*) as count FROM deliveries WHERE status = 'delivered' AND actual_delivery_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $kpis['recent_deliveries'] = $completedResult['data'][0]['count'] ?? 0;
            
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