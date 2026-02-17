<?php
/**
 * Sunny Query Executor
 * Handles SQL queries with role-based restrictions and security measures
 * ONLY allows SELECT/SHOW statements with proper access control
 */

class SunnyQueryExecutor {
    private $conn;
    private $userRole;
    private $userAccountId;
    private $allowedTables;
    
    public function __construct($userRole, $userAccountId = null) {
        // Normalize role casing (e.g. DDPm -> ddpm) to avoid allow-list mismatches.
        $this->userRole = strtolower(trim((string)$userRole));
        $this->userAccountId = $userAccountId;
        
        // Define allowed tables based on role
        $this->setAllowedTables();
        
        // Get database connection
        require_once dirname(__DIR__, 3) . '/config.php';
        $this->conn = getDBConnection();
        if (!$this->conn) {
            throw new Exception("Database connection failed");
        }
    }
    
    private function setAllowedTables() {
        // Expanded allow-list to match tools used by Sunny
        $common = [
            'projects', 'modules', 'deliveries', 'delivery_pallets', 'warehouses',
            'manufacturers', 'inventory_pallets', 'freight_estimates', 'warehouse_estimates',
            'project_invoices', 'project_wattage_orders', 'project_documents', 'sunny_memory',
            'unassigned_module_items', 'delivery_milestone_instances', 'module_batch_milestones',
            'flash_test_data', 'site_scheduling', 'warehouse_cost_items', 'accounts_payable'
        ];

        $this->allowedTables = [
            'global_admin' => array_merge($common, [
                'accounts', 'customer_accounts', 'users', 'customer_account_users'
            ]),
            'admin' => array_merge($common, [
                // Admins may need to read account-user mapping in some contexts
                'customer_account_users'
            ]),
            'customer_admin' => array_merge($common, [
                'customer_account_users'
            ]),
            'user' => $common,
            'ddpm' => $common
        ];
    }
    
    /**
     * Execute a safe SQL query with role-based filtering
     */
    public function executeQuery($sql, $params = []) {
        try {
            // 1. Validate SQL is read-only
            if (!$this->isReadOnlyQuery($sql)) {
                throw new Exception("Only SELECT and SHOW statements are allowed");
            }
            
            // 2. Validate table access
            $tables = $this->extractTablesFromQuery($sql);
            if (!$this->validateTableAccess($tables)) {
                throw new Exception("Access denied to one or more tables");
            }
            
            // 3. Apply role-based filtering
            $filteredSql = $this->applyRoleBasedFiltering($sql);
            
            // 4. Execute query
            $stmt = $this->conn->prepare($filteredSql);
            if (!$stmt) {
                throw new Exception("Query preparation failed: " . $this->conn->error);
            }
            
            // Bind parameters if provided
            if (!empty($params)) {
                $types = str_repeat('s', count($params)); // Default to string type
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result === false) {
                throw new Exception("Query execution failed: " . $stmt->error);
            }
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $this->sanitizeRowData($row);
            }
            
            $stmt->close();
            
            return [
                'success' => true,
                'data' => $data,
                'count' => count($data)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * Check if SQL query is read-only (SELECT/SHOW only)
     */
    private function isReadOnlyQuery($sql) {
        $sql = trim(strtoupper($sql));
        $allowedKeywords = ['SELECT', 'SHOW'];
        
        foreach ($allowedKeywords as $keyword) {
            if (strpos($sql, $keyword) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract table names from SQL query
     */
    private function extractTablesFromQuery($sql) {
        $tables = [];
        
        // Simple regex to extract table names after FROM and JOIN
        preg_match_all('/(?:FROM|JOIN)\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $matches);
        
        if (!empty($matches[1])) {
            $tables = array_map('strtolower', $matches[1]);
        }
        
        return array_unique($tables);
    }
    
    /**
     * Validate user has access to all tables in the query
     */
    private function validateTableAccess($tables) {
        $allowedTables = $this->allowedTables[$this->userRole] ?? [];
        
        foreach ($tables as $table) {
            if (!in_array($table, $allowedTables)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Apply role-based filtering to SQL query
     */
    private function applyRoleBasedFiltering($sql) {
        // IMPORTANT: We no longer inject tenant filters at this generic layer
        // because it is brittle with aliases and multi-table joins. Instead,
        // each tool query is responsible for explicitly scoping by account via
        // joins to the projects table and a "projects.account_id = ?" predicate.
        //
        // Leaving this method as a no-op preserves backwards compatibility
        // while avoiding accidental SQL errors from naive string injection.
        return $sql;
    }
    
    /**
     * Add account_id filtering to SQL query
     */
    private function addAccountIdFilter($sql) {
        // Only add the filter if at least one table in the query is known to have an account_id column
        $tablesWithAccountId = ['projects', 'modules', 'accounts', 'customer_accounts'];
        $tablesInQuery = $this->extractTablesFromQuery($sql);

        $hasAccountTable = false;
        foreach ($tablesInQuery as $tbl) {
            if (in_array($tbl, $tablesWithAccountId)) {
                $hasAccountTable = true;
                break;
            }
        }

        if (!$hasAccountTable) {
            return $sql; // Skip injection if no table supports account_id
        }

        // Deprecated: do not inject unqualified account_id conditions here.
        // Rely on explicit scoping in the tool queries.
        return $sql;
    }
    
    /**
     * Sanitize row data to remove sensitive information
     */
    private function sanitizeRowData($row) {
        // Remove or mask sensitive fields
        $sensitiveFields = ['password', 'password_hash', 'api_key', 'secret'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($row[$field])) {
                unset($row[$field]);
            }
        }
        
        // Mask email addresses for non-admin users
        if ($this->userRole !== 'global_admin' && $this->userRole !== 'admin' && $this->userRole !== 'customer_admin') {
            foreach ($row as $key => $value) {
                if (strpos($key, 'email') !== false && is_string($value)) {
                    $row[$key] = $this->maskEmail($value);
                }
            }
        }
        
        return $row;
    }
    
    /**
     * Mask email address for privacy
     */
    private function maskEmail($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        
        $parts = explode('@', $email);
        $username = $parts[0];
        $domain = $parts[1];
        
        $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(0, strlen($username) - 2));
        
        return $maskedUsername . '@' . $domain;
    }
    
    /**
     * Get summary statistics for a specific table
     */
    public function getTableSummary($tableName, $filters = []) {
        if (!in_array($tableName, $this->allowedTables[$this->userRole] ?? [])) {
            return [
                'success' => false,
                'error' => 'Access denied to table: ' . $tableName
            ];
        }
        
        try {
            $sql = "SELECT COUNT(*) as total_count FROM {$tableName}";
            
            // Apply role-based filtering
            if ($this->userRole !== 'global_admin' && $this->userAccountId) {
                $sql .= " WHERE account_id = {$this->userAccountId}";
            }
            
            $result = $this->executeQuery($sql);
            
            return [
                'success' => true,
                'table' => $tableName,
                'summary' => $result['data'][0] ?? ['total_count' => 0]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
    
    public function __destruct() {
        $this->close();
    }

    /**
     * Expose the live DB connection for trusted in-process helper calculations.
     */
    public function getConnection() {
        return $this->conn;
    }
} 
