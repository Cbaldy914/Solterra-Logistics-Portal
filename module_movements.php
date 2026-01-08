<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, and user roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'user'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

// Breadcrumb logic
$breadcrumb_link = '';
$breadcrumb_text = '';
$referer_page = isset($_SERVER['HTTP_REFERER']) ? basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH)) : '';

if ($batch_id > 0) {
    $breadcrumb_link = 'module_overview.php?batch_id=' . $batch_id;
    $breadcrumb_text = 'Module Batch Overview';
} elseif ($referer_page === 'project_overview.php' && $selected_project_id > 0) {
    $breadcrumb_link = 'project_overview.php?project_id=' . $selected_project_id;
    $breadcrumb_text = 'Project Overview';
}

// Increase memory limit for large datasets
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120'); // 2 minutes

// Performance tracking
$start_time = microtime(true);

// Account filtering for admin and user roles
$account_id_for_admin = null;
$user_account_ids = [];

if ($role === 'admin') {
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
} elseif ($role === 'user') {
    // Get all account IDs that this user has access to
    $sqlUserAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ?";
    $stmtUserAcc = $conn->prepare($sqlUserAcc);
    if ($stmtUserAcc) {
        $stmtUserAcc->bind_param("i", $user_id);
        $stmtUserAcc->execute();
        $resultUserAcc = $stmtUserAcc->get_result();
        while ($row = $resultUserAcc->fetch_assoc()) {
            $user_account_ids[] = $row['account_id'];
        }
        $stmtUserAcc->close();
    }
}

// Fetch available projects based on user role
$available_projects = [];
$project_data = null;
$movement_data = [];
$detailed_breakdown = [];
$errorMessage = '';

// If batch_id is provided but project_id is not, try to get project_id from the batch
if ($batch_id > 0 && $selected_project_id == 0) {
    $stmtBatch = $conn->prepare("SELECT project_id FROM modules WHERE id = ?");
    if ($stmtBatch) {
        $stmtBatch->bind_param("i", $batch_id);
        $stmtBatch->execute();
        $stmtBatch->bind_result($project_from_batch);
        if ($stmtBatch->fetch()) {
            $selected_project_id = $project_from_batch;
        }
        $stmtBatch->close();
    }
}

try {
    // Get projects based on role
    if ($role === 'global_admin') {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name, p.street_address, p.city, p.state, p.zip_code, c.name as account_name
            FROM projects p 
            JOIN customer_accounts c ON p.account_id = c.id 
            ORDER BY p.project_name ASC
        ");
    } else if ($role === 'admin' && $account_id_for_admin) {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name, p.street_address, p.city, p.state, p.zip_code, c.name as account_name
            FROM projects p 
            JOIN customer_accounts c ON p.account_id = c.id 
            WHERE p.account_id = ?
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param("i", $account_id_for_admin);
    } else if ($role === 'user' && !empty($user_account_ids)) {
        // Create placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($user_account_ids), '?'));
        $types = str_repeat('i', count($user_account_ids));
        
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name, p.street_address, p.city, p.state, p.zip_code, c.name as account_name
            FROM projects p 
            JOIN customer_accounts c ON p.account_id = c.id 
            WHERE p.account_id IN ($placeholders)
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param($types, ...$user_account_ids);
    } else {
        throw new Exception("Unable to determine user permissions or no accessible accounts found.");
    }
    
    if ($stmtProjects) {
        $stmtProjects->execute();
        $resultProjects = $stmtProjects->get_result();
        while ($project = $resultProjects->fetch_assoc()) {
            $available_projects[] = $project;
        }
        $stmtProjects->close();
    }

    // If a project is selected, get its movement data
    if ($selected_project_id > 0) {
        // Verify user can access this project
        $can_access = false;
        foreach ($available_projects as $proj) {
            if ($proj['id'] == $selected_project_id) {
                $can_access = true;
                $project_data = $proj;
                break;
            }
        }
        
        if (!$can_access) {
            throw new Exception("Access denied: You don't have permission to view this project's data.");
        }

        // First, get aggregated summary data instead of individual pallets for better performance
        $aggregated_movement_data = [];
        
        // Get aggregated data by location, status, manufacturer, and wattage
        $stmtAggregated = $conn->prepare("
            SELECT 
                -- Grouping fields
                ip.status,
                ip.wattage,
                COALESCE(m.vendor_name, 'Unknown Manufacturer') as manufacturer_name,
                COALESCE(mfg.name, 
                    CASE 
                        WHEN m.vendor_name LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(m.vendor_name, '-', 1))
                        ELSE m.vendor_name
                    END
                ) as manufacturer_company,
                COALESCE(ml.street_address, mfg.street_address, '') as mfg_street,
                COALESCE(ml.city, mfg.city, '') as mfg_city,
                COALESCE(ml.state, mfg.state, '') as mfg_state,
                COALESCE(ml.zip_code, mfg.zip_code, '') as mfg_zip,
                
                -- Warehouse info (current location)
                w2.id as current_warehouse_id_info,
                COALESCE(w2.name, '') as current_warehouse_name,
                COALESCE(w2.street_address, '') as current_wh_street,
                COALESCE(w2.city, '') as current_wh_city,
                COALESCE(w2.state, '') as current_wh_state,
                COALESCE(w2.zip_code, '') as current_wh_zip,
                
                -- Delivery warehouse info (destination from delivery)
                w.id as delivery_warehouse_id,
                COALESCE(w.name, '') as delivery_warehouse_name,
                COALESCE(w.street_address, '') as delivery_wh_street,
                COALESCE(w.city, '') as delivery_wh_city,
                COALESCE(w.state, '') as delivery_wh_state,
                COALESCE(w.zip_code, '') as delivery_wh_zip,

                -- Origin warehouse info (where delivery originated if from a warehouse)
                w_origin.id as origin_warehouse_id,
                COALESCE(w_origin.name, '') as origin_warehouse_name,
                COALESCE(w_origin.street_address, '') as origin_wh_street,
                COALESCE(w_origin.city, '') as origin_wh_city,
                COALESCE(w_origin.state, '') as origin_wh_state,
                COALESCE(w_origin.zip_code, '') as origin_wh_zip,
                
                -- Project info
                p.project_name,
                COALESCE(p.street_address, '') as proj_street,
                COALESCE(p.city, '') as proj_city,
                COALESCE(p.state, '') as proj_state,
                COALESCE(p.zip_code, '') as proj_zip,
                
                -- Aggregated counts
                COUNT(ip.id) as pallet_count,
                SUM(ip.quantity) as total_quantity,
                
                -- Origin tracking
                d.origin_type,
                d.origin_id
                
            FROM inventory_pallets ip
            
            -- Link to module batch for manufacturer info
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            
            -- Link to manufacturer_locations first, then manufacturers as fallback
            LEFT JOIN manufacturer_locations ml ON ip.manufacturer_location_id = ml.id
            LEFT JOIN manufacturers mfg ON (
                ml.manufacturer_id = mfg.id OR
                (ip.manufacturer_location_id IS NULL AND m.vendor_name IS NOT NULL 
                AND (
                    (LOCATE(' - ', m.vendor_name) > 0 AND mfg.name = TRIM(SUBSTRING_INDEX(m.vendor_name, ' - ', 1)))
                    OR (LOCATE(' - ', m.vendor_name) > 0 AND mfg.short_name = TRIM(SUBSTRING_INDEX(m.vendor_name, ' - ', 1)))
                    OR (LOCATE(' - ', m.vendor_name) = 0 AND mfg.name = m.vendor_name)
                    OR (LOCATE(' - ', m.vendor_name) = 0 AND mfg.short_name = m.vendor_name)
                ))
            )
            
            -- Join to the most recent delivery for each pallet to avoid
            -- duplicate rows when pallets have multiple deliveries
            LEFT JOIN (
                SELECT dp.inventory_pallet_id, MAX(dp.delivery_id) AS latest_delivery_id
                FROM delivery_pallets dp
                JOIN deliveries d2 ON dp.delivery_id = d2.id
                GROUP BY dp.inventory_pallet_id
            ) dp_latest ON dp_latest.inventory_pallet_id = ip.id
            LEFT JOIN deliveries d ON dp_latest.latest_delivery_id = d.id
            
            -- Link to project
            INNER JOIN projects p ON (
                m.project_id = p.id 
                OR ip.assigned_project_id = p.id 
                OR ip.current_project_id = p.id
                OR d.project_id = p.id
            )
            
            -- Link to warehouses
            LEFT JOIN warehouses w ON d.warehouse_id = w.id
            LEFT JOIN warehouses w2 ON ip.current_warehouse_id = w2.id
            LEFT JOIN warehouses w_origin ON (d.origin_type = 'warehouse' AND d.origin_id = w_origin.id)
            
            WHERE p.id = ?
            GROUP BY 
                ip.status, ip.wattage, m.vendor_name, 
                mfg.name, mfg.street_address, mfg.city, mfg.state, mfg.zip_code,
                w2.id, w2.name, w2.street_address, w2.city, w2.state, w2.zip_code,
                w.id, w.name, w.street_address, w.city, w.state, w.zip_code,
                w_origin.id, w_origin.name, w_origin.street_address, w_origin.city, w_origin.state, w_origin.zip_code,
                p.project_name, p.street_address, p.city, p.state, p.zip_code,
                d.origin_type, d.origin_id
            ORDER BY ip.status ASC, m.vendor_name ASC
        ");
        
        if ($stmtAggregated) {
            $stmtAggregated->bind_param("i", $selected_project_id);
            if (!$stmtAggregated->execute()) {
                throw new Exception("Failed to execute aggregated movement query: " . $stmtAggregated->error);
            }
            $resultAggregated = $stmtAggregated->get_result();
            
            if (!$resultAggregated) {
                throw new Exception("Failed to get aggregated movement results: " . $stmtAggregated->error);
            }
            
            while ($movement = $resultAggregated->fetch_assoc()) {
                $movement_data[] = $movement;
            }
            $stmtAggregated->close();
        } else {
            throw new Exception("Failed to prepare aggregated movement query: " . $conn->error);
        }
        
        // Calculate total pallets for performance warning
        $total_pallets = 0;
        foreach ($movement_data as $movement) {
            $total_pallets += $movement['pallet_count'];
        }
        
        // Calculate detailed breakdown from aggregated data
        if (!empty($movement_data)) {
            foreach ($movement_data as $movement) {
                $status = $movement['status'];
                $wattage = $movement['wattage'];
                $pallet_count = $movement['pallet_count']; // Now using aggregated count
                $total_quantity = $movement['total_quantity']; // Now using aggregated quantity
                
                // Create location-specific key for breakdown
                $breakdown_key = '';
                if ($status === 'In Warehouse' && $movement['current_warehouse_name']) {
                    $breakdown_key = 'In Warehouse - ' . $movement['current_warehouse_name'];
                } elseif ($status === 'Delivered to Project' && $movement['project_name']) {
                    $breakdown_key = 'Delivered to Project - ' . $movement['project_name'];
                } elseif ($status === 'In Transit to Project' && $movement['project_name']) {
                    $breakdown_key = 'In Transit to Project - ' . $movement['project_name'];
                } elseif ($status === 'In Transit to Warehouse' && $movement['delivery_warehouse_name']) {
                    $breakdown_key = 'In Transit to Warehouse - ' . $movement['delivery_warehouse_name'];
                } else {
                    $breakdown_key = $status;
                }
                
                // Initialize if not exists
                if (!isset($detailed_breakdown[$breakdown_key])) {
                    $detailed_breakdown[$breakdown_key] = [
                        'pallet_count' => 0,
                        'total_modules' => 0,
                        'wattage_breakdown' => []
                    ];
                }
                
                // Update counts with aggregated data
                $detailed_breakdown[$breakdown_key]['pallet_count'] += $pallet_count;
                $detailed_breakdown[$breakdown_key]['total_modules'] += $total_quantity;
                
                // Track wattage breakdown
                if (!isset($detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage])) {
                    $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage] = [
                        'pallets' => 0,
                        'modules' => 0
                    ];
                }
                
                $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['pallets'] += $pallet_count;
                $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['modules'] += $total_quantity;
            }
        }
        
        // Calculate pallet status breakdown separately to avoid double-counting from delivery JOINs
        $detailed_breakdown = [];
        if ($batch_id > 0) {
            // Get item IDs for this batch
            $batch_item_ids = [];
            $stmtBatchItems = $conn->prepare("SELECT id FROM unassigned_module_items WHERE unassigned_module_id = ?");
            if ($stmtBatchItems) {
                $stmtBatchItems->bind_param("i", $batch_id);
                $stmtBatchItems->execute();
                $resultBatchItems = $stmtBatchItems->get_result();
                while ($item = $resultBatchItems->fetch_assoc()) {
                    $batch_item_ids[] = $item['id'];
                }
                $stmtBatchItems->close();
            }

            // Fetch pallets for this batch (similar to module_overview.php approach)
            if (!empty($batch_item_ids)) {
                $placeholders = implode(',', array_fill(0, count($batch_item_ids), '?'));
                $types = str_repeat('i', count($batch_item_ids));

                $sqlPalletBreakdown = "SELECT ip.id, ip.wattage, ip.quantity, ip.status, ip.current_warehouse_id, ip.current_project_id, w.name as warehouse_name, p.project_name
                                       FROM inventory_pallets ip
                                       LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
                                       LEFT JOIN projects p ON ip.current_project_id = p.id
                                       WHERE ip.unassigned_module_item_id IN ($placeholders)
                                       ORDER BY ip.id ASC";
                $stmtPalletBreakdown = $conn->prepare($sqlPalletBreakdown);
                if ($stmtPalletBreakdown) {
                    $stmtPalletBreakdown->bind_param($types, ...$batch_item_ids);
                    $stmtPalletBreakdown->execute();
                    $resultPalletBreakdown = $stmtPalletBreakdown->get_result();

                    while ($pallet = $resultPalletBreakdown->fetch_assoc()) {
                        $status = $pallet['status'];
                        $wattage = $pallet['wattage'];
                        $quantity = $pallet['quantity'];

                        // Create location-specific key for breakdown
                        $breakdown_key = '';
                        $warehouse_id = null;
                        $warehouse_name = null;
                        $project_id_for_breakdown = null;
                        $project_name_for_breakdown = null;

                        if ($status === 'In Warehouse' && $pallet['warehouse_name']) {
                            $breakdown_key = 'In Warehouse - ' . $pallet['warehouse_name'];
                            $warehouse_id = $pallet['current_warehouse_id'];
                            $warehouse_name = $pallet['warehouse_name'];
                        } elseif ($status === 'Delivered to Project' && $pallet['project_name']) {
                            $breakdown_key = 'Delivered to Project - ' . $pallet['project_name'];
                            $project_id_for_breakdown = $pallet['current_project_id'];
                            $project_name_for_breakdown = $pallet['project_name'];
                        } elseif ($status === 'In Transit to Project' && $pallet['project_name']) {
                            $breakdown_key = 'In Transit to Project - ' . $pallet['project_name'];
                            $project_id_for_breakdown = $pallet['current_project_id'];
                            $project_name_for_breakdown = $pallet['project_name'];
                        } elseif ($status === 'In Transit to Warehouse' && $pallet['warehouse_name']) {
                            $breakdown_key = 'In Transit to Warehouse - ' . $pallet['warehouse_name'];
                            $warehouse_id = $pallet['current_warehouse_id'];
                            $warehouse_name = $pallet['warehouse_name'];
                        } else {
                            $breakdown_key = $status;
                        }

                        // Initialize if not exists
                        if (!isset($detailed_breakdown[$breakdown_key])) {
                            $detailed_breakdown[$breakdown_key] = [
                                'pallet_count' => 0,
                                'total_modules' => 0,
                                'wattage_breakdown' => [],
                                'warehouse_id' => $warehouse_id,
                                'warehouse_name' => $warehouse_name,
                                'project_id' => $project_id_for_breakdown,
                                'project_name' => $project_name_for_breakdown,
                                'pallet_ids' => []
                            ];
                        }

                        // Update counts
                        $detailed_breakdown[$breakdown_key]['pallet_count']++;
                        $detailed_breakdown[$breakdown_key]['total_modules'] += $quantity;
                        $detailed_breakdown[$breakdown_key]['pallet_ids'][] = $pallet['id'];

                        // Track wattage breakdown
                        if (!isset($detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage])) {
                            $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage] = [
                                'pallets' => 0,
                                'modules' => 0
                            ];
                        }

                        $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['pallets']++;
                        $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['modules'] += $quantity;
                    }
                    $stmtPalletBreakdown->close();
                }
            }
        } else {
            // For project-based view, use the existing movement data but be more careful about duplicates
            // This is a fallback for when batch_id is not available
            foreach ($movement_data as $movement) {
                $status = $movement['status'];
                $wattage = $movement['wattage'];
                $pallet_count = $movement['pallet_count'];
                $total_quantity = $movement['total_quantity'];

                // Create location-specific key for breakdown
                $breakdown_key = '';
                $warehouse_id = null;
                $warehouse_name = null;
                $project_id_for_breakdown = null;
                $project_name_for_breakdown = null;

                if ($status === 'In Warehouse' && $movement['current_warehouse_name']) {
                    $breakdown_key = 'In Warehouse - ' . $movement['current_warehouse_name'];
                    $warehouse_id = $movement['current_warehouse_id_info'];
                    $warehouse_name = $movement['current_warehouse_name'];
                } elseif ($status === 'Delivered to Project' && $movement['project_name']) {
                    $breakdown_key = 'Delivered to Project - ' . $movement['project_name'];
                    $project_id_for_breakdown = $selected_project_id;
                    $project_name_for_breakdown = $movement['project_name'];
                } elseif ($status === 'In Transit to Project' && $movement['project_name']) {
                    $breakdown_key = 'In Transit to Project - ' . $movement['project_name'];
                    $project_id_for_breakdown = $selected_project_id;
                    $project_name_for_breakdown = $movement['project_name'];
                } elseif ($status === 'In Transit to Warehouse' && $movement['delivery_warehouse_name']) {
                    $breakdown_key = 'In Transit to Warehouse - ' . $movement['delivery_warehouse_name'];
                    $warehouse_id = $movement['delivery_warehouse_id'];
                    $warehouse_name = $movement['delivery_warehouse_name'];
                } else {
                    $breakdown_key = $status;
                }

                // Initialize if not exists
                if (!isset($detailed_breakdown[$breakdown_key])) {
                    $detailed_breakdown[$breakdown_key] = [
                        'pallet_count' => 0,
                        'total_modules' => 0,
                        'wattage_breakdown' => [],
                        'warehouse_id' => $warehouse_id,
                        'warehouse_name' => $warehouse_name,
                        'project_id' => $project_id_for_breakdown,
                        'project_name' => $project_name_for_breakdown
                    ];
                }

                // Update counts with aggregated data
                $detailed_breakdown[$breakdown_key]['pallet_count'] += $pallet_count;
                $detailed_breakdown[$breakdown_key]['total_modules'] += $total_quantity;

                // Track wattage breakdown
                if (!isset($detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage])) {
                    $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage] = [
                        'pallets' => 0,
                        'modules' => 0
                    ];
                }

                $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['pallets'] += $pallet_count;
                $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['modules'] += $total_quantity;
            }
        }
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    // Log the error for debugging
    error_log("Module Movements Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Movements</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Modern Page Header */
        .movements-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .movements-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .movements-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .movements-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .movements-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }

        .controls-section {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .controls-section h2 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .project-selector {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .project-selector label {
            font-weight: 600;
            color: #333;
        }
        
        .project-selector select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            min-width: 250px;
        }
        
        .project-selector button {
            padding: 8px 16px;
            background: #488C9A;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .project-selector button:hover {
            background: #293E4C;
        }
        
        .project-info {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .project-info h3 {
            margin-top: 0;
            color: #293E4C;
        }
        
        .map-container {
            width: 100%;
            height: 600px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .map-legend {
            background-color: #f9f9f9;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .legend-marker {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .manufacturer-marker { background-color: #3498db; }
        .warehouse-marker { background-color: #f39c12; }
        .project-marker { background-color: #27ae60; }
        
        .movement-summary {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }
        
        .movement-summary h3 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .pallet-count {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .count-item {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
            text-align: center;
        }
        
        .count-number {
            font-size: 2em;
            font-weight: bold;
            color: #488C9A;
        }
        
        .count-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .no-project-message {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }

        /* Google Maps InfoWindow Styling Overrides */
        .gm-style-iw-c {
            padding: 0 !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
        }
        .gm-style-iw-d {
            overflow: visible !important;
        }
        .gm-style-iw-tc::after {
            background: white !important;
        }
        /* Hide the default close button container and reposition */
        .gm-style-iw-chr {
            position: absolute !important;
            top: 8px !important;
            right: 8px !important;
            height: auto !important;
            padding: 0 !important;
            margin: 0 !important;
            z-index: 10 !important;
        }
        .gm-style-iw-chr button {
            width: 24px !important;
            height: 24px !important;
            background: rgba(255,255,255,0.25) !important;
            border-radius: 6px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
        }
        .gm-style-iw-chr button:hover {
            background: rgba(255,255,255,0.4) !important;
        }
        .gm-style-iw-chr button span {
            background-color: white !important;
            margin: 0 !important;
        }
        /* Remove padding from main wrapper */
        .gm-style-iw-c > div:first-child {
            padding: 0 !important;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if ($batch_id > 0) { $extra[] = ['label' => 'Module Batch Overview', 'url' => 'module_overview.php?batch_id='.(int)$batch_id]; }
        elseif ($selected_project_id > 0) { $extra[] = ['label' => 'Project Overview', 'url' => 'project_overview.php?project_id='.(int)$selected_project_id]; }
        echo slp_render_breadcrumbs(['current_label' => 'Module Movements', 'extra' => $extra]);
    ?>

    <!-- Modern Page Header -->
    <div class="movements-header">
        <div class="movements-header-content">
            <div>
                <h1>Module Movement Tracking</h1>
                <p class="subtitle">Track module pallets from manufacturer to project site</p>
            </div>
        </div>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <div class="controls-section">
        <h2>Project Selection</h2>
        <form method="GET" class="project-selector">
            <?php if ($batch_id > 0): ?>
                <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
            <?php endif; ?>
            <label for="project_id">Select Project:</label>
            <select name="project_id" id="project_id" required>
                <option value="">-- Choose a Project --</option>
                <?php foreach ($available_projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php echo ($project['id'] == $selected_project_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($project['project_name']); ?>
                        <?php if ($role === 'global_admin'): ?>
                            (<?php echo htmlspecialchars($project['account_name']); ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">View Movement Map</button>
        </form>
    </div>

    <?php if ($selected_project_id > 0 && $project_data): ?>
        <div class="project-info">
            <h3><?php echo htmlspecialchars($project_data['project_name']); ?></h3>
            <p><strong>Location:</strong> 
                <?php 
                $address_parts = array_filter([
                    $project_data['street_address'],
                    $project_data['city'],
                    $project_data['state'],
                    $project_data['zip_code']
                ]);
                echo htmlspecialchars(implode(', ', $address_parts));
                ?>
            </p>
            <?php if ($role === 'global_admin'): ?>
                <p><strong>Account:</strong> <?php echo htmlspecialchars($project_data['account_name']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($movement_data)): ?>
            <!-- Map Legend and Status Breakdown Container -->
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <!-- Map Legend -->
                <div class="map-legend" style="flex: 1; margin-bottom: 0;">
                    <h3>Map Legend</h3>
                    <div class="legend-item">
                        <div class="legend-marker manufacturer-marker"></div>
                        <span>Manufacturer (Starting Point)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker warehouse-marker"></div>
                        <span>Warehouse (Intermediate Stop)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker project-marker"></div>
                        <span>Project Site (Final Destination)</span>
                    </div>
                    <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #eee; font-size: 11px; color: #888; display: flex; align-items: center; gap: 6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><path d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5"/></svg>
                        <span>Click locations or routes for details</span>
                    </div>
                </div>

                <!-- Pallet Status Breakdown -->
                <div class="movement-summary" style="flex: 1; margin-bottom: 0;">
                    <h3>Pallet Status Breakdown</h3>
                    <?php 
                    // Calculate totals for each main status
                    $status_totals = [
                        'At Manufacturer' => ['pallets' => 0, 'modules' => 0],
                        'In Warehouse' => ['pallets' => 0, 'modules' => 0],
                        'Delivered to Project' => ['pallets' => 0, 'modules' => 0]
                    ];
                    
                    // NEW: Check if any modules ever went through a warehouse
                    $warehouse_ever_used = false;
                    foreach ($movement_data as $movement) {
                        if (strpos($movement['status'], 'Warehouse') !== false || 
                            !empty($movement['current_warehouse_id_info']) ||
                            !empty($movement['delivery_warehouse_id'])) {
                            $warehouse_ever_used = true;
                            break;
                        }
                    }
                    // Also check detailed breakdown for any warehouse-related statuses
                    if (!$warehouse_ever_used && !empty($detailed_breakdown)) {
                        foreach ($detailed_breakdown as $status => $data) {
                            if (strpos($status, 'Warehouse') !== false) {
                                $warehouse_ever_used = true;
                                break;
                            }
                        }
                    }
                    
                    // Process the detailed breakdown to get totals
                    if (!empty($detailed_breakdown)) {
                        foreach ($detailed_breakdown as $status => $data) {
                            if (strpos($status, 'At Manufacturer') !== false) {
                                $status_totals['At Manufacturer']['pallets'] += $data['pallet_count'];
                                $status_totals['At Manufacturer']['modules'] += $data['total_modules'];
                            } elseif (strpos($status, 'In Warehouse') !== false) {
                                $status_totals['In Warehouse']['pallets'] += $data['pallet_count'];
                                $status_totals['In Warehouse']['modules'] += $data['total_modules'];
                            } elseif (strpos($status, 'Delivered to Project') !== false) {
                                $status_totals['Delivered to Project']['pallets'] += $data['pallet_count'];
                                $status_totals['Delivered to Project']['modules'] += $data['total_modules'];
                            }
                        }
                    }
                    ?>
                    
                    <div style="display: flex; justify-content: space-between; gap: 15px; align-items: center;">
                        <!-- At Manufacturer -->
                        <div onclick="showDetailedBreakdown('manufacturer')" style="flex: 1; text-align: center; padding: 12px; background-color: #e3f2fd; border-radius: 8px; border: 2px solid #3498db; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="font-weight: 600; color: #1565c0; margin-bottom: 6px; font-size: 0.9em;">
                                📍 At Manufacturer
                            </div>
                            <div style="color: #1976d2; font-size: 1.1em; font-weight: bold;">
                                <?php echo $status_totals['At Manufacturer']['pallets']; ?> pallets
                            </div>
                            <div style="color: #666; font-size: 0.85em;">
                                <?php echo number_format($status_totals['At Manufacturer']['modules']); ?> modules
                            </div>
                        </div>
                        
                        <?php if ($warehouse_ever_used): ?>
                        <!-- Arrow -->
                        <div style="color: #666; font-size: 1.5em;">→</div>
                        
                        <!-- In Warehouse -->
                        <div onclick="showDetailedBreakdown('warehouse')" style="flex: 1; text-align: center; padding: 12px; background-color: #fff3e0; border-radius: 8px; border: 2px solid #f39c12; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="font-weight: 600; color: #e65100; margin-bottom: 6px; font-size: 0.9em;">
                                🏢 In Warehouse
                            </div>
                            <div style="color: #f57c00; font-size: 1.1em; font-weight: bold;">
                                <?php echo $status_totals['In Warehouse']['pallets']; ?> pallets
                            </div>
                            <div style="color: #666; font-size: 0.85em;">
                                <?php echo number_format($status_totals['In Warehouse']['modules']); ?> modules
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Arrow -->
                        <div style="color: #666; font-size: 1.5em;">→</div>
                        
                        <!-- Delivered to Project -->
                        <div onclick="showDetailedBreakdown('project')" style="flex: 1; text-align: center; padding: 12px; background-color: #e8f5e8; border-radius: 8px; border: 2px solid #27ae60; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="font-weight: 600; color: #1b5e20; margin-bottom: 6px; font-size: 0.9em;">
                                🎯 Delivered to Project
                            </div>
                            <div style="color: #2e7d32; font-size: 1.1em; font-weight: bold;">
                                <?php echo $status_totals['Delivered to Project']['pallets']; ?> pallets
                            </div>
                            <div style="color: #666; font-size: 0.85em;">
                                <?php echo number_format($status_totals['Delivered to Project']['modules']); ?> modules
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Google Map -->
            <div id="map" class="map-container"></div>
        <?php else: ?>
            <div class="no-project-message">
                <p>No pallet movement data found for this project.</p>
                <p>Pallets may not have been created or shipped yet.</p>
            </div>
        <?php endif; ?>
    <?php elseif ($selected_project_id > 0): ?>
        <div class="no-project-message">
            <p>Selected project not found or you don't have access to it.</p>
        </div>
    <?php else: ?>
        <div class="no-project-message">
            <p>Please select a project above to view its module movement map.</p>
        </div>
    <?php endif; ?>

</main>

<!-- Detailed Breakdown Modal -->
<div id="detailModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #fefefe; margin: 5% auto; padding: 30px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; position: relative;">
        <span onclick="closeDetailModal()" style="color: #aaa; position: absolute; top: 15px; right: 25px; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        <h2 id="modalTitle" style="margin-top: 0; color: #293E4C; border-bottom: 2px solid #488C9A; padding-bottom: 10px;"></h2>
        <div id="modalContent"></div>
    </div>
</div>

<!-- Load Google Maps JavaScript API -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
// Movement data from PHP
const movementData = <?php echo json_encode($movement_data ?? []); ?>;
const projectData = <?php echo json_encode($project_data ?? null); ?>;
const detailedBreakdown = <?php echo json_encode($detailed_breakdown ?? []); ?>;
const selectedProjectId = <?php echo json_encode($selected_project_id); ?>;

let map;
let directionsService;
let directionsRenderer;

function initMap() {
    if (!projectData || movementData.length === 0) {
        return;
    }
    
    console.log(`Initializing map with ${movementData.length} aggregated location groups`);

    // Initialize map centered on the US with performance optimizations
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 4,
        center: { lat: 39.8283, lng: -98.5795 }, // Center of US
        mapTypeId: 'roadmap',
        // Performance optimizations for large datasets
        disableDefaultUI: false,
        zoomControl: true,
        mapTypeControl: true,
        scaleControl: true,
        streetViewControl: false,
        rotateControl: false,
        fullscreenControl: true
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        suppressMarkers: true, // We'll add custom markers
        polylineOptions: {
            strokeColor: '#488C9A',
            strokeWeight: 3,
            strokeOpacity: 0.8
        }
    });
    directionsRenderer.setMap(map);

    // Process movement data to create markers and routes
    processMovementData();
}

function processMovementData() {
    const locations = new Map();
    const routes = [];
    
    // Safety check
    if (!movementData || !Array.isArray(movementData) || movementData.length === 0) {
        console.log('No movement data available');
        return;
    }
    
    console.log(`Processing ${movementData.length} aggregated location groups`);
    
    // Process each aggregated movement group
    movementData.forEach(movement => {
        // Add manufacturer location for ALL groups that have manufacturer info
        if (movement.manufacturer_name) {
            const mfgKey = 'mfg_' + movement.manufacturer_name;
            if (!locations.has(mfgKey)) {
                // Use manufacturer company name if available, otherwise extract from vendor_name
                const manufacturerName = movement.manufacturer_company || movement.manufacturer_name.split(' - ')[0] || movement.manufacturer_name;
                const manufacturerAddress = buildAddress(movement.mfg_street, movement.mfg_city, movement.mfg_state, movement.mfg_zip);
                
                locations.set(mfgKey, {
                    type: 'manufacturer',
                    name: manufacturerName,
                    address: manufacturerAddress || 'Manufacturer Location', // Fallback if no address
                    pallets: [],
                    total_pallets: 0,
                    total_modules: 0,
                    marker: null
                });
            }
            // Only add aggregated data to manufacturer location if they're currently AT manufacturer
            if (movement.status === 'At Manufacturer') {
                const location = locations.get(mfgKey);
                location.pallets.push(movement);
                location.total_pallets += movement.pallet_count;
                location.total_modules += movement.total_quantity;
            }
        }

        // Add warehouse location with pallets currently IN warehouse
        if (movement.status === 'In Warehouse' && movement.current_warehouse_id_info && movement.current_warehouse_name) {
            const whKey = 'wh_' + movement.current_warehouse_id_info;
            if (!locations.has(whKey)) {
                locations.set(whKey, {
                    type: 'warehouse',
                    id: movement.current_warehouse_id_info,
                    name: movement.current_warehouse_name,
                    address: buildAddress(movement.current_wh_street, movement.current_wh_city, movement.current_wh_state, movement.current_wh_zip),
                    pallets: [],
                    total_pallets: 0,
                    total_modules: 0,
                    marker: null
                });
            }
            const location = locations.get(whKey);
            location.pallets.push(movement);
            location.total_pallets += movement.pallet_count;
            location.total_modules += movement.total_quantity;
        }

        // Add origin warehouse location even if no pallets currently there
        if (movement.origin_type === 'warehouse' && movement.origin_warehouse_id && movement.origin_warehouse_name) {
            const whOrigKey = 'wh_' + movement.origin_warehouse_id;
            if (!locations.has(whOrigKey)) {
                locations.set(whOrigKey, {
                    type: 'warehouse',
                    id: movement.origin_warehouse_id,
                    name: movement.origin_warehouse_name,
                    address: buildAddress(movement.origin_wh_street, movement.origin_wh_city, movement.origin_wh_state, movement.origin_wh_zip),
                    pallets: [],
                    total_pallets: 0,
                    total_modules: 0,
                    marker: null
                });
            }
        }

        // Add project location with pallets DELIVERED to project
        if (movement.status === 'Delivered to Project') {
            const projectKey = 'proj_' + projectData.id;
            if (!locations.has(projectKey)) {
                locations.set(projectKey, {
                    type: 'project',
                    id: projectData.id,
                    name: projectData.project_name,
                    address: buildAddress(projectData.street_address, projectData.city, projectData.state, projectData.zip_code),
                    pallets: [],
                    total_pallets: 0,
                    total_modules: 0,
                    marker: null
                });
            }
            const location = locations.get(projectKey);
            location.pallets.push(movement);
            location.total_pallets += movement.pallet_count;
            location.total_modules += movement.total_quantity;
        }
    });

    // Always add project location even if no pallets delivered yet (for future reference)
    const projectKey = 'proj_' + projectData.id;
    if (!locations.has(projectKey)) {
        locations.set(projectKey, {
            type: 'project',
            id: projectData.id,
            name: projectData.project_name,
            address: buildAddress(projectData.street_address, projectData.city, projectData.state, projectData.zip_code),
            pallets: [],
            total_pallets: 0,
            total_modules: 0,
            marker: null
        });
    }

    // Geocode and create markers for all locations
    const geocoder = new google.maps.Geocoder();
    const bounds = new google.maps.LatLngBounds();
    let geocodePromises = [];

    locations.forEach((location, key) => {
        if (location.address) {
            const promise = geocodeAddress(geocoder, location.address)
                .then(position => {
                    if (position) {
                        location.position = position;
                        createMarker(location);
                        bounds.extend(position);
                    }
                })
                .catch(error => {
                    console.error('Geocoding failed for:', location.name, error);
                });
            geocodePromises.push(promise);
        }
    });

    // After all geocoding is complete, fit map to bounds
    Promise.all(geocodePromises).then(() => {
        if (!bounds.isEmpty()) {
            map.fitBounds(bounds);
            
            // Ensure minimum zoom level
            google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                if (map.getZoom() > 15) {
                    map.setZoom(15);
                }
            });
        }
        
        // Prevent marker overlap by adjusting positions
        adjustMarkerPositions(locations);
        
        // Create route lines after markers are positioned
        createRouteLines(locations);
    });
}

function buildAddress(street, city, state, zip) {
    const parts = [street, city, state, zip].filter(part => part && part.trim() !== '' && part !== null && part !== undefined);
    return parts.length > 0 ? parts.join(', ') : '';
}

function geocodeAddress(geocoder, address) {
    return new Promise((resolve, reject) => {
        geocoder.geocode({ address: address }, (results, status) => {
            if (status === 'OK' && results[0]) {
                resolve(results[0].geometry.location);
            } else {
                reject(new Error('Geocoding failed: ' + status));
            }
        });
    });
}

function createMarker(location) {
    if (!location.position) return;

    let icon;
    const iconSize = 40; // Larger, more prominent bubble-style markers
    
    switch (location.type) {
        case 'manufacturer':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#3498db" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">M</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
        case 'warehouse':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#f39c12" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">W</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
        case 'project':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#27ae60" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">P</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
    }

    const marker = new google.maps.Marker({
        position: location.position,
        map: map,
        title: location.name,
        icon: icon,
        zIndex: 20 // Ensure markers are on top
    });

    // Create enhanced info window with current quantities and wattages from aggregated data
    const totalPallets = parseInt(location.total_pallets) || location.pallets.length;
    // Recalculate total modules from pallets to avoid string concatenation issues
    let totalModules = 0;
    const wattageBreakdown = {};

    // Calculate quantities by wattage from aggregated data
    location.pallets.forEach(group => {
        const wattage = group.wattage;
        const modules = parseInt(group.total_quantity || group.quantity || 0);
        totalModules += modules;
        if (!wattageBreakdown[wattage]) {
            wattageBreakdown[wattage] = 0;
        }
        wattageBreakdown[wattage] += modules;
    });

    const wattageDetails = Object.keys(wattageBreakdown)
        .sort()
        .map(w => `${w}W: ${wattageBreakdown[w].toLocaleString()} modules`)
        .join('<br>');

    // Determine colors and icons based on type
    let typeColor, typeIcon, typeBgColor, viewPalletsUrl, nameLink;

    switch (location.type) {
        case 'manufacturer':
            typeColor = '#1565c0';
            typeBgColor = '#e3f2fd';
            typeIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1565c0" stroke-width="2"><path d="M2 20h20M5 20V8.8l7-4.2 7 4.2V20M9 20v-5h6v5"/></svg>';
            viewPalletsUrl = `manage_pallets.php?project_id=${selectedProjectId}&status=At%20Manufacturer&from=module_movements`;
            nameLink = location.name;
            break;
        case 'warehouse':
            typeColor = '#e65100';
            typeBgColor = '#fff3e0';
            typeIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e65100" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>';
            viewPalletsUrl = `manage_pallets.php?project_id=${selectedProjectId}&status=In%20Warehouse&from=module_movements`;
            nameLink = location.id
                ? `<a href="warehouse_info.php?warehouse_id=${location.id}&project_id=${selectedProjectId}&from=module_movements" style="color: ${typeColor}; text-decoration: none; font-weight: 600;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${location.name}</a>`
                : location.name;
            break;
        case 'project':
            typeColor = '#1b5e20';
            typeBgColor = '#e8f5e9';
            typeIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1b5e20" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 7 8 11.7z"/></svg>';
            viewPalletsUrl = `manage_pallets.php?project_id=${location.id || selectedProjectId}&status=Delivered%20to%20Project&from=module_movements`;
            nameLink = location.id
                ? `<a href="project_overview.php?project_id=${location.id}&from=module_movements" style="color: ${typeColor}; text-decoration: none; font-weight: 600;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${location.name}</a>`
                : location.name;
            break;
    }

    // Build gradient color for button
    const gradientColors = {
        'manufacturer': 'linear-gradient(135deg, #3498db 0%, #2980b9 100%)',
        'warehouse': 'linear-gradient(135deg, #f39c12 0%, #e67e22 100%)',
        'project': 'linear-gradient(135deg, #27ae60 0%, #1e8449 100%)'
    };

    // Build the clickable name based on location type
    let clickableName;
    if (location.type === 'warehouse' && location.id) {
        clickableName = `<a href="warehouse_info.php?warehouse_id=${location.id}&project_id=${selectedProjectId}&from=module_movements" style="color: white; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${location.name}</a>`;
    } else if (location.type === 'project' && location.id) {
        clickableName = `<a href="project_overview.php?project_id=${location.id}&from=module_movements" style="color: white; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${location.name}</a>`;
    } else {
        clickableName = location.name;
    }

    const infoContent = `
        <div class="map-popup-content" style="font-family: 'Poppins', Arial, sans-serif; padding: 0;">
            <!-- Colored Header Bar with Title and right padding for X button -->
            <div class="map-popup-header" style="background: ${gradientColors[location.type]}; padding: 12px 36px 12px 14px; display: flex; align-items: center; gap: 10px;">
                <div style="display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: rgba(255,255,255,0.2); border-radius: 8px; flex-shrink: 0;">
                    ${typeIcon.replace(/stroke="[^"]*"/g, 'stroke="white"')}
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 15px; font-weight: 600; color: white; line-height: 1.2;">${clickableName}</div>
                    <div style="font-size: 11px; color: rgba(255,255,255,0.8); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">${location.type}</div>
                </div>
            </div>

            <!-- Content Area -->
            <div style="padding: 12px 14px;">
                <!-- Address -->
                <div style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; padding: 8px 10px; background: #f8f9fa; border-radius: 6px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span style="font-size: 12px; color: #555; line-height: 1.4;">${location.address || 'Address not available'}</span>
                </div>

                <!-- Inventory Stats -->
                <div style="background: ${typeBgColor}; padding: 12px; border-radius: 8px; margin-bottom: 12px; border: 1px solid ${typeColor}20;">
                    <div style="font-weight: 600; color: ${typeColor}; margin-bottom: 8px; font-size: 12px; display: flex; align-items: center; gap: 5px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        Current Inventory
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div style="text-align: center; padding: 6px; background: white; border-radius: 5px;">
                            <div style="font-size: 18px; font-weight: 700; color: #293E4C;">${totalPallets.toLocaleString()}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase;">Pallets</div>
                        </div>
                        <div style="text-align: center; padding: 6px; background: white; border-radius: 5px;">
                            <div style="font-size: 18px; font-weight: 700; color: #293E4C;">${totalModules.toLocaleString()}</div>
                            <div style="font-size: 10px; color: #666; text-transform: uppercase;">Modules</div>
                        </div>
                    </div>
                    ${wattageDetails ? `
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid ${typeColor}30;">
                        <div style="font-size: 11px; color: #666; margin-bottom: 3px;">Wattage Breakdown:</div>
                        <div style="font-size: 11px; color: #333; line-height: 1.5;">${wattageDetails}</div>
                    </div>
                    ` : ''}
                </div>

                <!-- View Pallets Button -->
                <a href="${viewPalletsUrl}" style="display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 14px; background: ${gradientColors[location.type]}; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    View Pallets
                </a>
            </div>
        </div>
    `;

    const infoWindow = new google.maps.InfoWindow({
        content: infoContent
    });

    // Open popup on click only
    marker.addListener('click', () => {
        infoWindow.open(map, marker);
    });

    location.marker = marker;
}

function adjustMarkerPositions(locations) {
    const markerPositions = [];
    const minDistance = 0.2; // Even more separation for clean bubble layout
    
    // Collect all markers with their positions
    locations.forEach((location, key) => {
        if (location.marker && location.position) {
            markerPositions.push({
                key: key,
                location: location,
                lat: location.position.lat(),
                lng: location.position.lng(),
                originalLat: location.position.lat(),
                originalLng: location.position.lng()
            });
        }
    });
    
    // Check for overlaps and create clean bubble layout
    for (let i = 0; i < markerPositions.length; i++) {
        for (let j = i + 1; j < markerPositions.length; j++) {
            const marker1 = markerPositions[i];
            const marker2 = markerPositions[j];
            
            const distance = Math.sqrt(
                Math.pow(marker1.lat - marker2.lat, 2) + 
                Math.pow(marker1.lng - marker2.lng, 2)
            );
            
            if (distance < minDistance) {
                // Create a clean radial layout around the center point
                const centerLat = (marker1.originalLat + marker2.originalLat) / 2;
                const centerLng = (marker1.originalLng + marker2.originalLng) / 2;
                
                const offsetDistance = minDistance * 0.8;
                
                // Position markers in a clean circle around center
                const angle1 = (i * Math.PI * 2) / markerPositions.length;
                const angle2 = (j * Math.PI * 2) / markerPositions.length;
                
                marker1.lat = centerLat + Math.sin(angle1) * offsetDistance;
                marker1.lng = centerLng + Math.cos(angle1) * offsetDistance;
                
                marker2.lat = centerLat + Math.sin(angle2) * offsetDistance;
                marker2.lng = centerLng + Math.cos(angle2) * offsetDistance;
                
                // Update marker positions
                const newPosition1 = new google.maps.LatLng(marker1.lat, marker1.lng);
                const newPosition2 = new google.maps.LatLng(marker2.lat, marker2.lng);
                
                marker1.location.marker.setPosition(newPosition1);
                marker1.location.position = newPosition1;
                
                marker2.location.marker.setPosition(newPosition2);
                marker2.location.position = newPosition2;
            }
        }
    }
    
    // Add clean pointer lines from displaced markers back to their actual locations
    markerPositions.forEach(markerPos => {
        const wasDisplaced = (markerPos.lat !== markerPos.originalLat || markerPos.lng !== markerPos.originalLng);
        
        if (wasDisplaced) {
            const originalPosition = new google.maps.LatLng(markerPos.originalLat, markerPos.originalLng);
            const currentPosition = new google.maps.LatLng(markerPos.lat, markerPos.lng);
            
            // Create a clean, simple pointer line (like in the examples)
            const pointerLine = new google.maps.Polyline({
                path: [currentPosition, originalPosition],
                geodesic: false,
                strokeColor: '#FFFFFF', // White line for clean look
                strokeOpacity: 0.9,
                strokeWeight: 3,
                map: map,
                zIndex: 8 // Above routes but below markers
            });
            
            // Add a subtle shadow/outline to the pointer line
            const shadowLine = new google.maps.Polyline({
                path: [currentPosition, originalPosition],
                geodesic: false,
                strokeColor: '#000000',
                strokeOpacity: 0.3,
                strokeWeight: 5,
                map: map,
                zIndex: 7 // Behind the white line
            });
            
            // Add a small dot at the actual location (cleaner than the previous version)
            const actualLocationDot = new google.maps.Marker({
                position: originalPosition,
                map: map,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                        '<svg width="12" height="12" viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg">' +
                        '<circle cx="6" cy="6" r="4" fill="#FFFFFF" stroke="#333333" stroke-width="2"/>' +
                        '<circle cx="6" cy="6" r="2" fill="#333333"/>' +
                        '</svg>'
                    ),
                    scaledSize: new google.maps.Size(12, 12),
                    anchor: new google.maps.Point(6, 6)
                },
                title: `Exact location: ${markerPos.location.name}`,
                zIndex: 15 // Highest priority
            });
            
            // Store references
            markerPos.location.pointerLine = pointerLine;
            markerPos.location.shadowLine = shadowLine;
            markerPos.location.actualLocationDot = actualLocationDot;
        }
    });
}

// Add route visualization after all markers are created
function createRouteLines(locations) {
    if (!map) return;
    
    const routes = new Map();
    
    // Analyze movement patterns to create routes based on aggregated delivery history
    movementData.forEach(movement => {
        const manufacturerKey = 'mfg_' + movement.manufacturer_name;
        const projectKey = 'proj_' + projectData.id;
        
        // Determine warehouse key based on where pallets were delivered or are currently stored
        let warehouseKey = null;
        if (movement.current_warehouse_id_info && movement.current_warehouse_name) {
            warehouseKey = 'wh_' + movement.current_warehouse_id_info;
        } else if (movement.delivery_warehouse_id && movement.delivery_warehouse_name) {
            warehouseKey = 'wh_' + movement.delivery_warehouse_id;
        }

        // Origin warehouse for deliveries leaving a warehouse
        let originWarehouseKey = null;
        if (movement.origin_type === 'warehouse' && movement.origin_warehouse_id && movement.origin_warehouse_name) {
            originWarehouseKey = 'wh_' + movement.origin_warehouse_id;
        }
        
        // Create manufacturer → warehouse route (for pallets that either reside in a warehouse or were delivered to project via that warehouse)
        if (warehouseKey && (movement.status === 'In Warehouse' || movement.status === 'Delivered to Project')) {
            const route1Key = `${manufacturerKey}_to_${warehouseKey}`;
            if (!routes.has(route1Key)) {
                routes.set(route1Key, {
                    from: manufacturerKey,
                    to: warehouseKey,
                    pallets: [],
                    modules: 0,
                    pallet_count: 0,
                    color: '#488C9A', // Blue for manufacturer → warehouse
                    type: 'manufacturer_to_warehouse'
                });
            }
            const route = routes.get(route1Key);
            route.pallets.push(movement);
            route.modules += parseInt(movement.total_quantity);
            route.pallet_count += parseInt(movement.pallet_count);
        }

        // Create manufacturer → origin warehouse route for pallets delivered to project (to preserve historical path)
        if (!warehouseKey && originWarehouseKey && movement.status === 'Delivered to Project') {
            const routeOrigKey = `${manufacturerKey}_to_${originWarehouseKey}`;
            if (!routes.has(routeOrigKey)) {
                routes.set(routeOrigKey, {
                    from: manufacturerKey,
                    to: originWarehouseKey,
                    pallets: [],
                    modules: 0,
                    pallet_count: 0,
                    color: '#488C9A',
                    type: 'manufacturer_to_warehouse'
                });
            }
            const route = routes.get(routeOrigKey);
            route.pallets.push(movement);
            route.modules += parseInt(movement.total_quantity);
            route.pallet_count += parseInt(movement.pallet_count);
        }
        
        // Create warehouse → project route (for aggregated groups delivered to project)
        if ((warehouseKey || originWarehouseKey) && movement.status === 'Delivered to Project') {
            const whKeyForRoute = warehouseKey || originWarehouseKey;
            const route2Key = `${whKeyForRoute}_to_${projectKey}`;
            if (!routes.has(route2Key)) {
                routes.set(route2Key, {
                    from: whKeyForRoute,
                    to: projectKey,
                    pallets: [],
                    modules: 0,
                    pallet_count: 0,
                    color: '#27ae60', // Green for warehouse → project
                    type: 'warehouse_to_project'
                });
            }
            const route = routes.get(route2Key);
            route.pallets.push(movement);
            route.modules += parseInt(movement.total_quantity);
            route.pallet_count += parseInt(movement.pallet_count);
        }
        
        // Create direct manufacturer → project route (for aggregated groups that skipped warehouse)
        if (!warehouseKey && movement.status === 'Delivered to Project') {
            // Check if this is actually a direct delivery from manufacturer (not via warehouse)
            // Use the new origin tracking data to make this determination
            const isDirectFromManufacturer = movement.origin_type === 'manufacturer';
            const isFromWarehouse = movement.origin_type === 'warehouse';
            
            if (isDirectFromManufacturer && !isFromWarehouse) {
                const directRouteKey = `${manufacturerKey}_to_${projectKey}`;
                if (!routes.has(directRouteKey)) {
                    routes.set(directRouteKey, {
                        from: manufacturerKey,
                        to: projectKey,
                        pallets: [],
                        modules: 0,
                        pallet_count: 0,
                        color: '#e74c3c', // Red for direct routes
                        type: 'manufacturer_to_project_direct'
                    });
                }
                const route = routes.get(directRouteKey);
                route.pallets.push(movement);
                route.modules += parseInt(movement.total_quantity);
                route.pallet_count += parseInt(movement.pallet_count);
            }
            // If origin_type is 'warehouse', this delivery will be handled by the warehouse → project route logic above
        }
    });
    
    // Create polylines for each route
    routes.forEach((route, routeKey) => {
        const fromLocation = locations.get(route.from);
        const toLocation = locations.get(route.to);
        
        if (fromLocation && toLocation && fromLocation.position && toLocation.position && route.modules > 0) {
            // Calculate the total modules in the project to determine volume percentage
            const totalProjectModules = movementData.reduce((sum, movement) => sum + parseInt(movement.total_quantity || movement.quantity || 0), 0);
            const volumePercentage = route.modules / totalProjectModules;
            
            // Determine line thickness based on volume (5 levels)
            let strokeWeight = 2; // Default minimum thickness
            
            if (volumePercentage >= 0.8) {
                // Very high volume: thickest line (10px)
                strokeWeight = 10;
            } else if (volumePercentage >= 0.6) {
                // High volume: thick line (8px)
                strokeWeight = 8;
            } else if (volumePercentage >= 0.4) {
                // Medium volume: medium line (6px)
                strokeWeight = 6;
            } else if (volumePercentage >= 0.2) {
                // Low volume: thin line (4px)
                strokeWeight = 4;
            } else {
                // Very low volume: thinnest line (2px)
                strokeWeight = 2;
            }
            
            console.log(`Creating route from ${fromLocation.name} to ${toLocation.name}: ${route.modules} modules (${Math.round(volumePercentage * 100)}% of total), thickness: ${strokeWeight}px`);

            const polylineOptions = {
                path: [fromLocation.position, toLocation.position],
                geodesic: true,
                strokeColor: route.color,
                strokeOpacity: 0.8,
                strokeWeight: strokeWeight, // Variable thickness based on volume
                map: map,
                zIndex: 5 // Lower than markers (which are zIndex: 20)
            };

            const polyline = new google.maps.Polyline(polylineOptions);
            
            // Add info window for routes (click only)
            const routeInfoWindow = new google.maps.InfoWindow();

            // Click to open route info - but ignore clicks near markers
            polyline.addListener('click', (event) => {
                // Check if click is near the start or end marker (within ~0.05 degrees ≈ 5km)
                const clickLat = event.latLng.lat();
                const clickLng = event.latLng.lng();
                const threshold = 0.08; // Degrees threshold for "near marker"

                const nearFromMarker = Math.abs(clickLat - fromLocation.position.lat()) < threshold &&
                                       Math.abs(clickLng - fromLocation.position.lng()) < threshold;
                const nearToMarker = Math.abs(clickLat - toLocation.position.lat()) < threshold &&
                                     Math.abs(clickLng - toLocation.position.lng()) < threshold;

                // If click is near a marker, don't show route popup (let marker handle it)
                if (nearFromMarker || nearToMarker) {
                    return;
                }

                const routeTypeLabel = route.type === 'manufacturer_to_warehouse' ? 'Manufacturer → Warehouse' :
                                     route.type === 'warehouse_to_project' ? 'Warehouse → Project' :
                                     'Manufacturer → Project (Direct)';

                const routeIcon = route.type === 'manufacturer_to_warehouse'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>'
                    : route.type === 'warehouse_to_project'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>'
                    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M13 17l5-5-5-5M6 17l5-5-5-5"/></svg>';

                const routeGradient = route.type === 'manufacturer_to_warehouse'
                    ? 'linear-gradient(135deg, #3498db 0%, #2980b9 100%)'
                    : route.type === 'warehouse_to_project'
                    ? 'linear-gradient(135deg, #27ae60 0%, #1e8449 100%)'
                    : 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)';

                const routeInfo = `
                    <div style="font-family: 'Poppins', Arial, sans-serif; padding: 0;">
                        <!-- Colored Header Bar with right padding for X button -->
                        <div style="background: ${routeGradient}; padding: 10px 36px 10px 12px; display: flex; align-items: center; gap: 8px;">
                            <div style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: rgba(255,255,255,0.2); border-radius: 6px; flex-shrink: 0;">
                                ${routeIcon}
                            </div>
                            <div style="flex: 1; white-space: nowrap;">
                                <div style="font-size: 13px; font-weight: 600; color: white; line-height: 1.2;">${routeTypeLabel}</div>
                                <div style="font-size: 10px; color: rgba(255,255,255,0.8);">Shipment Route</div>
                            </div>
                        </div>
                        <!-- Content -->
                        <div style="padding: 10px 12px;">
                            <div style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px;">
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap;">
                                    <span style="color: #888; width: 40px;">From:</span>
                                    <span style="color: #333; font-weight: 500;">${fromLocation.name}</span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap;">
                                    <span style="color: #888; width: 40px;">To:</span>
                                    <span style="color: #333; font-weight: 500;">${toLocation.name}</span>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #f8f9fa; padding: 10px; border-radius: 6px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 16px; font-weight: 700; color: #293E4C;">${(route.pallet_count || route.pallets.length).toLocaleString()}</div>
                                    <div style="font-size: 10px; color: #666; text-transform: uppercase;">Pallets</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 16px; font-weight: 700; color: #293E4C;">${route.modules.toLocaleString()}</div>
                                    <div style="font-size: 10px; color: #666; text-transform: uppercase;">Modules</div>
                                </div>
                            </div>
                            <div style="margin-top: 8px; font-size: 10px; color: #888; text-align: center; font-style: italic;">
                                Line thickness represents volume
                            </div>
                        </div>
                    </div>
                `;
                routeInfoWindow.setContent(routeInfo);
                routeInfoWindow.setPosition(event.latLng);
                routeInfoWindow.open(map);
            });
            
            // Store route reference for debugging
            route.polyline = polyline;
        }
    });
    
    console.log(`Created ${routes.size} routes:`, routes);
}

// Initialize map when page loads
google.maps.event.addDomListener(window, 'load', initMap);

// Modal functions
function showDetailedBreakdown(type) {
    const modal = document.getElementById('detailModal');
    const title = document.getElementById('modalTitle');
    const content = document.getElementById('modalContent');
    
    let titleText = '';
    let contentHtml = '';
    
    if (type === 'manufacturer') {
        titleText = '📍 At Manufacturer - Detailed Breakdown';
        contentHtml = generateManufacturerBreakdown();
    } else if (type === 'warehouse') {
        titleText = '🏢 In Warehouse - Detailed Breakdown';
        contentHtml = generateWarehouseBreakdown();
    } else if (type === 'project') {
        titleText = '🎯 Delivered to Project - Detailed Breakdown';
        contentHtml = generateProjectBreakdown();
    }
    
    title.textContent = titleText;
    content.innerHTML = contentHtml;
    modal.style.display = 'block';
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function generateManufacturerBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;

    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('At Manufacturer')) {
            hasData = true;
            const data = detailedBreakdown[status];
            const manufacturerName = status.replace('At Manufacturer', '').replace(' - ', '').trim() || 'Manufacturer';

            // Create View Pallets link with status filter
            const viewPalletsUrl = `manage_pallets.php?project_id=${selectedProjectId}&status=At%20Manufacturer&from=module_movements`;

            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #3498db;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <h4 style="margin: 0; color: #1565c0;">${status}</h4>
                    </div>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;

            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul style="margin-bottom: 15px;">';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }

            html += `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                        <a href="${viewPalletsUrl}" class="view-pallets-link" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 0.9em; font-weight: 500; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(52,152,219,0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            View Pallets
                        </a>
                    </div>
                </div>
            `;
        }
    });

    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules currently at manufacturer.</p>';
    }

    html += '</div>';
    return html;
}

function generateWarehouseBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;

    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('In Warehouse')) {
            hasData = true;
            const data = detailedBreakdown[status];
            const warehouseId = data.warehouse_id;
            const warehouseName = data.warehouse_name || status.replace('In Warehouse - ', '');

            // Create clickable warehouse link with referrer
            const warehouseLink = warehouseId
                ? `<a href="warehouse_info.php?warehouse_id=${warehouseId}&project_id=${selectedProjectId}&from=module_movements" style="color: #e65100; text-decoration: none; font-weight: 600;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${warehouseName}</a>`
                : warehouseName;

            // Create View Pallets link with status filter
            const viewPalletsUrl = `manage_pallets.php?project_id=${selectedProjectId}&status=In%20Warehouse&from=module_movements`;

            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #f39c12;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <h4 style="margin: 0; color: #e65100;">
                            <span style="color: #666; font-weight: normal;">In Warehouse - </span>${warehouseLink}
                        </h4>
                    </div>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;

            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul style="margin-bottom: 15px;">';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }

            html += `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                        <a href="${viewPalletsUrl}" class="view-pallets-link" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 0.9em; font-weight: 500; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(243,156,18,0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            View Pallets
                        </a>
                    </div>
                </div>
            `;
        }
    });

    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules currently in warehouse.</p>';
    }

    html += '</div>';
    return html;
}

function generateProjectBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;

    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('Delivered to Project')) {
            hasData = true;
            const data = detailedBreakdown[status];
            const projectId = data.project_id || selectedProjectId;
            const projectName = data.project_name || (projectData ? projectData.project_name : status.replace('Delivered to Project - ', ''));

            // Create clickable project link with referrer
            const projectLink = projectId
                ? `<a href="project_overview.php?project_id=${projectId}&from=module_movements" style="color: #1b5e20; text-decoration: none; font-weight: 600;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">${projectName}</a>`
                : projectName;

            // Create View Pallets link with status filter
            const viewPalletsUrl = `manage_pallets.php?project_id=${projectId || selectedProjectId}&status=Delivered%20to%20Project&from=module_movements`;

            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #27ae60;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <h4 style="margin: 0; color: #1b5e20;">
                            <span style="color: #666; font-weight: normal;">Delivered to Project - </span>${projectLink}
                        </h4>
                    </div>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;

            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul style="margin-bottom: 15px;">';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }

            html += `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #eee;">
                        <a href="${viewPalletsUrl}" class="view-pallets-link" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: linear-gradient(135deg, #27ae60 0%, #1e8449 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 0.9em; font-weight: 500; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(39,174,96,0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            View Pallets
                        </a>
                    </div>
                </div>
            `;
        }
    });

    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules have been delivered to project yet.</p>';
    }

    html += '</div>';
    return html;
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('detailModal');
    if (event.target === modal) {
        closeDetailModal();
    }
}
</script>
</body>
</html> 
