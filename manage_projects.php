<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

$projects = [];
$errorMessage = '';
$successMessage = '';

// Note: Delete handling is now done via AJAX endpoints for better user experience

// --- Fetch Projects with Enhanced Data --- 
$sqlProjects        = "";
$paramTypesProjects = "";
$paramsProjects     = [];
$account_id_for_admin = null; // Define it here for potential reuse

// If the user is global_admin, they can see all projects
if ($role === 'global_admin') {
    $sqlProjects = "
        SELECT
            p.id,
            p.project_name,
            p.image_url,
            c.name AS account_name,
            p.estimated_completion_date,
            p.project_address,
            p.street_address,
            p.city,
            p.state,
            p.zip_code,
            COALESCE(p.project_size, 0) AS project_size,
            COALESCE((
                SELECT COUNT(*)
                FROM deliveries d3
                WHERE d3.project_id = p.id
                  AND (d3.status_of_delivery IS NULL OR d3.status_of_delivery <> 'Canceled')
            ), 0) AS delivery_count,
            (
                SELECT COUNT(*) FROM inventory_pallets ip
                LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                  AND ip.status = 'Delivered to Project'
            ) AS delivered_pallets,
            (
                SELECT COUNT(*) FROM inventory_pallets ip
                LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
            ) AS total_pallets
        FROM projects p
        JOIN customer_accounts c ON p.account_id = c.id
        WHERE (p.status IS NULL OR p.status = 'active')
        ORDER BY c.name ASC, p.project_name ASC
    ";
} elseif (in_array($role, ['admin', 'customer_admin'], true)) {
    // Look up the account_id for account-scoped admin roles
    $sqlOne = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) die("Error preparing account lookup: " . $conn->error);
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($account_id_for_admin);
    $stmtOne->fetch();
    $stmtOne->close();

    if (empty($account_id_for_admin)) {
        // Handle case where admin has no assigned account - maybe show message or redirect
        // For now, we'll let the project query return empty results.
         $sqlProjects = "SELECT NULL LIMIT 0"; // No projects if no account
    } else {
        $sqlProjects = "
            SELECT
                p.id,
                p.project_name,
                p.image_url,
                c.name AS account_name,
                p.estimated_completion_date,
                p.project_address,
                p.street_address,
                p.city,
                p.state,
                p.zip_code,
                COALESCE(p.project_size, 0) AS project_size,
                COALESCE((
                    SELECT COUNT(*)
                    FROM deliveries d3
                    WHERE d3.project_id = p.id
                      AND (d3.status_of_delivery IS NULL OR d3.status_of_delivery <> 'Canceled')
                ), 0) AS delivery_count,
                (
                    SELECT COUNT(*) FROM inventory_pallets ip
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                      AND ip.status = 'Delivered to Project'
                ) AS delivered_pallets,
                (
                    SELECT COUNT(*) FROM inventory_pallets ip
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                ) AS total_pallets
            FROM projects p
            JOIN customer_accounts c ON p.account_id = c.id
            WHERE p.account_id = ? AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ";
        $paramTypesProjects = "i";
        $paramsProjects     = [$account_id_for_admin];
    }
} else {
    header("Location: unauthorized");
    exit();
}

// Prepare and execute the projects query
$stmtProjects = $conn->prepare($sqlProjects);
if (!$stmtProjects) die("Error preparing projects query: " . $conn->error);
if (!empty($paramTypesProjects)) {
    $stmtProjects->bind_param($paramTypesProjects, ...$paramsProjects);
}
$stmtProjects->execute();
$resultProjects = $stmtProjects->get_result();

// Health counts for stats
$health_counts = ['on_track' => 0, 'at_risk' => 0, 'behind' => 0, 'completed' => 0];
$total_pallets_all = 0;
$total_storage_modules = 0;

if ($resultProjects) {
    while ($project = $resultProjects->fetch_assoc()) {
        $project_id = $project['id'];

        // Fetch order data from project_wattage_orders (like dashboard.php)
        $stmt_wattage = $conn->prepare("SELECT wattage, total_order FROM project_wattage_orders WHERE project_id = ? ORDER BY wattage ASC");
        $stmt_wattage->bind_param("i", $project_id);
        $stmt_wattage->execute();
        $wattage_result = $stmt_wattage->get_result();

        $total_order_quantity = 0;
        $weighted_wattage_sum = 0;
        while ($wattage_row = $wattage_result->fetch_assoc()) {
            $qty = (int)$wattage_row['total_order'];
            $watt = (float)$wattage_row['wattage'];
            $total_order_quantity += $qty;
            $weighted_wattage_sum += ($qty * $watt);
        }
        $stmt_wattage->close();

        $avg_wattage = $total_order_quantity > 0 ? $weighted_wattage_sum / $total_order_quantity : 0;
        $project['total_modules'] = $total_order_quantity;
        $project['ordered_mw'] = ($total_order_quantity * $avg_wattage) / 1000000;
        $project['order_progress'] = $project['project_size'] > 0 ? min(100, ($project['ordered_mw'] / $project['project_size']) * 100) : 0;

        // Fetch delivered MW (like dashboard.php)
        $stmt_delivered = $conn->prepare("SELECT d.wattage, SUM(d.quantity) as delivered_qty FROM deliveries d WHERE d.project_id = ? AND d.status_of_delivery = 'Delivered to Project' GROUP BY d.wattage");
        $stmt_delivered->bind_param("i", $project_id);
        $stmt_delivered->execute();
        $delivered_result = $stmt_delivered->get_result();

        $total_delivered = 0;
        $delivered_mw = 0;
        while ($del_row = $delivered_result->fetch_assoc()) {
            $del_qty = (int)$del_row['delivered_qty'];
            $del_watt = (float)$del_row['wattage'];
            $total_delivered += $del_qty;
            $delivered_mw += ($del_qty * $del_watt) / 1000000;
        }
        $stmt_delivered->close();

        $project['delivered_modules'] = $total_delivered;
        $project['delivered_mw'] = $delivered_mw;
        $project['delivery_progress'] = $project['project_size'] > 0 ? min(100, ($delivered_mw / $project['project_size']) * 100) : 0;

        // Fetch storage modules (like dashboard.php)
        $stmt_storage = $conn->prepare("SELECT SUM(ip.quantity) AS total_in_storage FROM inventory_pallets ip WHERE ip.assigned_project_id = ? AND ip.status = 'In Warehouse'");
        $stmt_storage->bind_param("i", $project_id);
        $stmt_storage->execute();
        $stmt_storage->bind_result($total_in_storage);
        $stmt_storage->fetch();
        $stmt_storage->close();
        $total_in_storage = $total_in_storage ?: 0;
        $project['storage_modules'] = $total_in_storage;
        $project['storage_percent'] = $total_order_quantity > 0 ? round(($total_in_storage / $total_order_quantity) * 100) : 0;
        $total_storage_modules += $total_in_storage;

        // Legacy pallet-based progress calculations
        $delivered_pallets = (int)($project['delivered_pallets'] ?? 0);
        $total_pallets = (int)($project['total_pallets'] ?? 0);
        $total_pallets_all += $total_pallets;

        // Determine project status
        $project_status = 'Planning';
        if ($total_pallets > 0) {
            if ($project['delivery_progress'] >= 100) {
                $project_status = 'Completed';
            } elseif ($project['delivery_progress'] > 0) {
                $project_status = 'In Progress';
            } else {
                $project_status = 'Ready to Ship';
            }
        }

        // Calculate health (same logic as dashboard.php)
        $today = new DateTime();
        $health = 'on_track';
        $health_text = 'On Track';
        $health_reason = 'Project is progressing normally';

        if ($project['delivery_progress'] >= 100) {
            $health = 'completed';
            $health_text = 'Completed';
            $health_reason = 'All modules have been delivered to the project site';
        } elseif (!empty($project['estimated_completion_date'])) {
            $est_date = new DateTime($project['estimated_completion_date']);
            $diff = $today->diff($est_date);
            $days_remaining = $diff->days;
            $is_past = $est_date < $today;

            if ($is_past && $project['delivery_progress'] < 100) {
                $health = 'behind';
                $health_text = 'Behind';
                $health_reason = 'Past completion date (' . $est_date->format('M j, Y') . ') with ' . round($project['delivery_progress']) . '% delivered';
            } elseif (!$is_past && $days_remaining <= 30 && $project['delivery_progress'] < 80) {
                $health = 'at_risk';
                $health_text = 'At Risk';
                $health_reason = $days_remaining . ' days until deadline with only ' . round($project['delivery_progress']) . '% delivered';
            } else {
                $health_reason = $is_past ? 'Completed on schedule' : $days_remaining . ' days remaining with ' . round($project['delivery_progress']) . '% delivered';
            }
        }

        $health_counts[$health]++;

        $project['project_status'] = $project_status;
        $project['health'] = $health;
        $project['health_text'] = $health_text;
        $project['health_reason'] = $health_reason;
        $projects[] = $project;
    }
}
$stmtProjects->close();

// Calculate summary statistics
$total_projects = count($projects);
$total_mw = array_sum(array_map(fn($p) => (float)($p['project_size'] ?? 0), $projects));

// Get archived projects count
$archived_count = 0;
if ($role === 'global_admin') {
    $stmtArchived = $conn->prepare("SELECT COUNT(*) FROM archived_projects");
    $stmtArchived->execute();
    $stmtArchived->bind_result($archived_count);
    $stmtArchived->fetch();
    $stmtArchived->close();
} elseif ($account_id_for_admin) {
    $stmtArchived = $conn->prepare("SELECT COUNT(*) FROM archived_projects WHERE account_id = ?");
    $stmtArchived->bind_param("i", $account_id_for_admin);
    $stmtArchived->execute();
    $stmtArchived->bind_result($archived_count);
    $stmtArchived->fetch();
    $stmtArchived->close();
}

$conn->close(); // Close connection after fetching project data
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Header Section - Exact Match to Global Documents */
        .projects-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .projects-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 120px;
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }

        .pipeline-chart-stat {
            min-width: 140px;
            padding: 10px 16px;
        }

        .pipeline-chart-container {
            width: 60px;
            height: 60px;
            margin: 0 auto 4px;
        }

        .pipeline-legend {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 0.65em;
            margin-top: 4px;
        }

        .pipeline-legend-item {
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .pipeline-legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .add-project-btn {
            background: #488C9A;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .add-project-btn:hover {
            background: #3A6E7F;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(72, 140, 154, 0.2);
        }
        
        /* Responsive Header */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .header-stats {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            
            .stat-item {
                min-width: 100px;
                padding: 12px 16px;
            }
            
            .header-info h1 {
                font-size: 2rem;
            }
            
            .header-subtitle {
                font-size: 1rem;
            }
        }
        /* Section Header Row - Dashboard Style */
        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin-bottom: 20px;
        }
        .section-title-row { display: flex; align-items: center; gap: 12px; }
        .section-header {
            font-size: 1.3em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }
        .section-controls { display: flex; align-items: center; gap: 12px; }
        .view-archived-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 20px;
            color: #6c757d;
            font-size: 0.8em;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }
        .view-archived-link:hover { background: #e8f4f7; border-color: #488C9A; color: #488C9A; }
        .archived-count { background: #488C9A; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.9em; font-weight: 600; }
        .view-toggle { display: inline-flex; background: #f1f3f4; border-radius: 8px; padding: 3px; gap: 3px; }
        .view-toggle button { padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 1em; border: none; cursor: pointer; color: #6c757d; background: transparent; transition: all 0.2s; }
        .view-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .view-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        /* Project Cards - Dashboard Style */
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 28px; justify-content: start; max-width: 1400px; margin-top: 0; }
        @media (min-width: 1400px) { .projects-grid { grid-template-columns: repeat(4, 1fr); } }
        .projects-grid.active { display: grid; }
        .projects-grid:not(.active) { display: none; }

        .project-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; transition: all 0.2s; cursor: pointer; }
        .project-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
        .project-card-image { width: 100%; height: 160px; background: #f0f2f4; position: relative; overflow: hidden; }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(58,110,127,0.9)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 1em; font-weight: 600; }
        .project-card-content { padding: 14px; }
        .project-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; gap: 8px; }
        .project-card-title { margin: 0; font-size: 1.05em; color: #293E4C; font-weight: 600; flex: 1; line-height: 1.3; }
        .project-card-location { color: #6c757d; font-size: 0.75em; margin-bottom: 12px; }

        /* Progress Rings Row - matching dashboard.php */
        .progress-rings-row { display: flex; justify-content: center; gap: 24px; margin-bottom: 12px; }
        .progress-ring-fill.order { stroke: #488C9A; }

        /* Storage Badge */
        .storage-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: #fff3cd; border-radius: 6px; font-size: 0.7em; color: #856404; margin-top: 8px; }
        .storage-badge strong { color: #664d03; }
        .project-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0; }

        /* Health Badges */
        .health-badge { padding: 3px 8px; border-radius: 10px; font-size: 0.65em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; cursor: help; position: relative; }
        .health-badge:hover .health-tooltip { opacity: 1; visibility: visible; }
        .health-tooltip { position: absolute; bottom: calc(100% + 6px); right: 0; background: #293E4C; color: #fff; padding: 8px 10px; border-radius: 6px; font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; white-space: normal; width: 180px; text-align: left; opacity: 0; visibility: hidden; transition: all 0.2s; z-index: 100; line-height: 1.4; }
        .health-tooltip::after { content: ''; position: absolute; top: 100%; right: 10px; border: 5px solid transparent; border-top-color: #293E4C; }
        .health-on_track { background: #d4edda; color: #155724; }
        .health-at_risk { background: #fff3cd; color: #856404; }
        .health-behind { background: #f8d7da; color: #721c24; }
        .health-completed { background: #cce7ff; color: #004085; }

        /* Progress Rings */
        .progress-ring-item { text-align: center; padding: 6px; border-radius: 8px; transition: background 0.2s; }
        .progress-ring { width: 64px; height: 64px; position: relative; margin: 0 auto 4px; }
        .progress-ring svg { transform: rotate(-90deg); }
        .progress-ring-bg { fill: none; stroke: #e9ecef; stroke-width: 5; }
        .progress-ring-fill { fill: none; stroke-width: 5; stroke-linecap: round; transition: stroke-dashoffset 0.8s; }
        .progress-ring-fill.delivery { stroke: #28a745; }
        .progress-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .progress-ring-value { font-size: 0.95em; font-weight: 700; color: #293E4C; line-height: 1; }
        .progress-ring-label { font-size: 0.55em; color: #6c757d; }
        .ring-title { font-size: 0.7em; color: #495057; font-weight: 500; }
        .ring-subtitle { font-size: 0.65em; color: #999; }

        /* Project Info Grid */
        .project-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 10px; }
        .info-item { padding: 6px 8px; background: #f8f9fa; border-radius: 6px; }
        .info-label { font-size: 0.65em; color: #6c757d; }
        .info-value { font-size: 0.85em; font-weight: 600; color: #293E4C; }

        /* Card Actions */
        .project-card-actions { display: flex; gap: 8px; padding-top: 10px; border-top: 1px solid #f0f0f0; margin-top: 10px; }
        .btn-view { flex: 1; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: white; padding: 10px 16px; border: none; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.85em; text-align: center; transition: all 0.2s; }
        .btn-view:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3); }

        /* Add Project Card */
        .add-project-card { display: flex; align-items: center; justify-content: center; flex-direction: column; border: 2px dashed #d0d0d0; background: #f9f9f9; color: #6c757d; min-height: 360px; border-radius: 12px; cursor: pointer; transition: all 0.2s; }
        .add-project-card:hover { border-color: #488C9A; background: #f0f8fa; color: #488C9A; transform: translateY(-4px); }
        .add-project-icon { font-size: 3em; margin-bottom: 10px; }
        .add-project-text { font-size: 1.1em; font-weight: 600; }

        /* Table View */
        .projects-table-container { background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef; overflow: hidden; display: none; }
        .projects-table-container.active { display: block; }
        .projects-table { width: 100%; border-collapse: collapse; }
        .projects-table th { background: #488C9A; padding: 12px 14px; text-align: left; font-weight: 600; color: #fff; font-size: 0.8em; white-space: nowrap; }
        .projects-table td { padding: 12px 14px; border-bottom: 1px solid #f1f3f4; color: #495057; font-size: 0.85em; vertical-align: middle; }
        .projects-table tr { cursor: pointer; transition: background 0.2s; }
        .projects-table tbody tr:hover { background: #f8f9fa; }
        .table-project-name { font-weight: 600; color: #293E4C; }
        .table-progress { display: flex; align-items: center; gap: 8px; }
        .table-progress-bar { flex: 1; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; min-width: 60px; }
        .table-progress-fill { height: 100%; border-radius: 3px; }
        .table-progress-fill.order { background: linear-gradient(90deg, #488C9A, #5AA8B7); }
        .table-progress-fill.delivery { background: linear-gradient(90deg, #28a745, #34ce57); }
        .table-progress-text { font-weight: 600; min-width: 40px; font-size: 0.9em; }
        .table-progress-text.order { color: #488C9A; }
        .table-progress-text.delivery { color: #28a745; }
        .table-actions { display: flex; gap: 6px; }
        .table-btn { padding: 5px 10px; border-radius: 5px; font-size: 0.75em; font-weight: 500; text-decoration: none; transition: all 0.2s; border: none; cursor: pointer; }
        .table-btn-view { background: #488C9A; color: white; }
        .table-btn-view:hover { background: #3A6E7F; }
        .table-btn-edit { background: #f8f9fa; color: #488C9A; border: 1px solid #dee2e6; }
        .table-btn-edit:hover { background: #e9ecef; }
        .table-btn-delete { background: #f8f9fa; color: #dc3545; border: 1px solid #dee2e6; }
        .table-btn-delete:hover { background: #f8d7da; }
        /* Clean up old styles - replaced with modern card design */
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        /* Enhanced responsive design */
        @media (max-width: 1200px) {
            .projects-grid {
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .project-card {
                padding: 20px;
            }
            
            .project-metrics {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        /* Dropdown menu styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-toggle {
            background: none;
            border: none;
            color: #488C9A;
            padding: 4px;
            cursor: pointer;
            font-size: 1.1em;
            border-radius: 3px;
            transition: all 0.2s ease;
        }
        .dropdown-toggle:hover {
            background-color: #f8f9fa;
            color: #293E4C;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            bottom: auto;
            background-color: white;
            min-width: 140px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            border-radius: 4px;
            z-index: 1000;
            border: 1px solid #ddd;
            right: 0;
            left: auto;
            /* Animation setup */
            opacity: 0;
            transform: translateY(6px) scale(0.98);
            transform-origin: top right;
            transition: opacity 140ms ease, transform 140ms ease;
            will-change: opacity, transform;
        }
        
        /* Alternative positioning for dropdowns that would overflow */
        .dropdown-menu.dropdown-left {
            right: auto;
            left: 0;
        }

        /* Show above the toggle button */
        .dropdown-menu.dropdown-up {
            top: auto;
            bottom: calc(100% + 6px);
            transform-origin: bottom right;
            /* initial offset for animation when hidden */
            transform: translateY(-6px) scale(0.98);
        }

        /* Show to the right of the toggle */
        .dropdown-menu.dropdown-side-right {
            top: 0;
            bottom: auto;
            left: calc(100% + 6px);
            right: auto;
            transform-origin: top left;
            transform: translateX(6px) scale(0.98);
        }

        /* Show to the left of the toggle */
        .dropdown-menu.dropdown-side-left {
            top: 0;
            bottom: auto;
            right: calc(100% + 6px);
            left: auto;
            transform-origin: top right;
            transform: translateX(-6px) scale(0.98);
        }
        
        /* For very small screens, make dropdown smaller */
        @media (max-width: 768px) {
            .dropdown-menu {
                min-width: 120px;
            }
        }
        .dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translate(0, 0) scale(1);
        }
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .dropdown-item.edit {
            color: #488C9A;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .dropdown-item:first-child {
            border-radius: 4px 4px 0 0;
        }
        .dropdown-item:last-child {
            border-radius: 0 0 4px 4px;
        }
        
        /* Enhanced dropdown styling for cards */
        .project-actions .dropdown {
            position: relative;
        }
        
        .project-actions .dropdown-toggle {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 1rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .project-actions .dropdown-toggle:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        /* Delete modal styling */
        .delete-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .delete-modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            position: relative;
        }
        
        .delete-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .delete-modal-close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        
        .delete-modal-close:hover {
            color: #000;
        }
        
        .delete-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .delete-warning h4 {
            margin-top: 0;
            color: #dc3545;
        }
        
        .deletion-summary {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .deletion-summary h4 {
            margin-top: 0;
            color: #293E4C;
        }
        
        .delete-item {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 1.1em;
        }
        
        .delete-item:last-child {
            border-bottom: none;
        }
        
        .delete-sub-item {
            padding: 4px 0;
            color: #666;
            font-size: 0.95em;
        }
        
        .delete-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        
        .btn-delete-confirm {
            background-color: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
        }
        
        .btn-delete-confirm:hover {
            background-color: #c82333;
        }
        
        .btn-delete-confirm:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
    </style>
    <script>
        // Enhanced delete confirmation with detailed information
        function confirmDelete(projectName, projectId) {
            // First get deletion info via AJAX
            fetch(`get_project_delete_info.php?project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }
                    
                    // Show detailed confirmation modal
                    showDeleteModal(data);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching project deletion information');
                });
        }
        
        function showDeleteModal(data) {
            const modal = document.getElementById('deleteModal');
            const project = data.project;
            const counts = data.counts;
            
            // Update modal content
            document.getElementById('modalProjectName').textContent = project.name;
            document.getElementById('modalAccountName').textContent = project.account_name;
            
            // Build deletion summary
            let summaryHtml = '';
            
            if (counts.deliveries > 0) {
                summaryHtml += `<div class="delete-item">📦 <strong>${counts.deliveries}</strong> delivery record${counts.deliveries !== 1 ? 's' : ''}</div>`;
            }
            
            if (counts.module_batches > 0) {
                summaryHtml += `<div class="delete-item">📋 <strong>${counts.module_batches}</strong> module batch${counts.module_batches !== 1 ? 'es' : ''}</div>`;
                if (counts.total_modules > 0) {
                    summaryHtml += `<div class="delete-sub-item">    → ${counts.total_modules.toLocaleString()} total modules</div>`;
                }
            }
            
            if (counts.pallets > 0) {
                summaryHtml += `<div class="delete-item">📦 <strong>${counts.pallets}</strong> pallet record${counts.pallets !== 1 ? 's' : ''}</div>`;
            }
            
            if (counts.delivery_pallets > 0) {
                summaryHtml += `<div class="delete-item">🔗 <strong>${counts.delivery_pallets}</strong> delivery-pallet link${counts.delivery_pallets !== 1 ? 's' : ''}</div>`;
            }
            
            if (summaryHtml === '') {
                summaryHtml = '<div class="delete-item">✅ No associated data to delete</div>';
            }
            
            document.getElementById('deletionSummary').innerHTML = summaryHtml;
            
            // Store project ID for actual deletion
            document.getElementById('confirmDeleteBtn').setAttribute('data-project-id', project.id);
            document.getElementById('confirmDeleteBtn').setAttribute('data-project-name', project.name);
            
            // Show modal
            modal.style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function executeDelete() {
            const btn = document.getElementById('confirmDeleteBtn');
            const projectId = btn.getAttribute('data-project-id');
            const projectName = btn.getAttribute('data-project-name');
            
            // Disable button and show loading
            btn.disabled = true;
            btn.textContent = 'Deleting...';
            
            // Execute deletion
            const formData = new FormData();
            formData.append('project_id', projectId);
            
            fetch('delete_project_cascade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message and reload page
                    alert(`Success: ${data.message}`);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                    // Re-enable button
                    btn.disabled = false;
                    btn.textContent = 'Yes, Delete Everything';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting project');
                // Re-enable button
                btn.disabled = false;
                btn.textContent = 'Yes, Delete Everything';
            });
        }
        
        // Dropdown functionality with smart positioning
        function toggleDropdown(event, dropdownId) {
            event.stopPropagation();

            const toggle = event.currentTarget;

            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== dropdownId) {
                    menu.classList.remove('show', 'dropdown-left', 'dropdown-up', 'dropdown-side-right', 'dropdown-side-left');
                }
            });

            // Toggle the clicked dropdown
            const dropdown = document.getElementById(dropdownId);
            const isShowing = dropdown.classList.contains('show');

            if (isShowing) {
                dropdown.classList.remove('show');
                return;
            }

            // Reset positioning classes
            dropdown.classList.remove('dropdown-left', 'dropdown-up', 'dropdown-side-right', 'dropdown-side-left');

            // Measure size without triggering animation or visual flicker
            const prevDisplay = dropdown.style.display;
            const prevVisibility = dropdown.style.visibility;
            dropdown.style.display = 'block';
            dropdown.style.visibility = 'hidden';

            // Check menu size and available space
            const menuRect = dropdown.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            const windowWidth = window.innerWidth;
            const windowHeight = window.innerHeight;

            const spaceBelow = windowHeight - toggleRect.bottom;
            const spaceAbove = toggleRect.top;
            const spaceRight = windowWidth - toggleRect.right;
            const spaceLeft = toggleRect.left;

            // Decide vertical or side placement
            if (spaceBelow >= menuRect.height + 8) {
                // default: below (no class needed)
            } else if (spaceAbove >= menuRect.height + 8) {
                dropdown.classList.add('dropdown-up');
            } else if (spaceRight >= menuRect.width + 8) {
                dropdown.classList.add('dropdown-side-right');
            } else {
                dropdown.classList.add('dropdown-side-left');
            }
            
            // Restore styles and show with animation
            dropdown.style.display = prevDisplay;
            dropdown.style.visibility = prevVisibility;
            dropdown.classList.add('show');

            // For below/above placements, ensure it doesn't overflow off the right edge
            if (!dropdown.classList.contains('dropdown-side-right') && !dropdown.classList.contains('dropdown-side-left')) {
                const rectAfter = dropdown.getBoundingClientRect();
                if (rectAfter.right > windowWidth - 8) {
                    dropdown.classList.add('dropdown-left');
                }
            }
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        });
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Projects']); ?>
    
    <div class="projects-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Manage Projects</h1>
                    <p class="header-subtitle">Comprehensive project management and tracking dashboard</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item">
                    <p class="stat-number"><?php echo $total_projects; ?></p>
                    <p class="stat-label">Active Projects</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo number_format($total_mw, 1); ?></p>
                    <p class="stat-label">Total MW</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo number_format($total_storage_modules); ?></p>
                    <p class="stat-label">In Storage</p>
                </div>
                <div class="stat-item pipeline-chart-stat">
                    <div class="pipeline-chart-container">
                        <canvas id="pipelineChartMini"></canvas>
                    </div>
                    <p class="stat-label">Pipeline Status</p>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>

    <div class="section-header-row">
        <div class="section-title-row">
            <h2 class="section-header">Active Projects</h2>
            <a href="archived_projects.php" class="view-archived-link">
                Archived <span class="archived-count"><?php echo $archived_count; ?></span>
            </a>
        </div>
        <div class="section-controls">
            <div class="view-toggle">
                <button class="active" onclick="setView('grid')" id="btn-grid" title="Grid View">▦</button>
                <button onclick="setView('table')" id="btn-table" title="Table View">☰</button>
            </div>
            <a href="add_project.php" class="add-project-btn">+ Add Project</a>
        </div>
    </div>

    <!-- Grid View -->
    <div class="projects-grid active" id="projects-grid">
        <?php if (!empty($projects)): ?>
            <?php
            $health_definitions = [
                'on_track' => 'On schedule relative to completion date.',
                'at_risk' => 'Less than 30 days to deadline with <80% delivered.',
                'behind' => 'Past completion date, not fully delivered.',
                'completed' => 'All modules delivered to site.'
            ];
            foreach ($projects as $project):
                $project_size_mw = (float)($project['project_size'] ?? 0);
                $delivery_progress = $project['delivery_progress'] ?? 0;
                $order_progress = $project['order_progress'] ?? 0;
                $address_parts = array_filter([$project['street_address'], $project['city'], $project['state'], $project['zip_code']]);
                $formatted_address = implode(', ', $address_parts);
                $circ = 2 * 3.14159 * 26;
                $order_offset = $circ - ($order_progress / 100) * $circ;
                $delivery_offset = $circ - ($delivery_progress / 100) * $circ;
                $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : 'N/A';
            ?>
            <div class="project-card" onclick="window.location.href='project_overview.php?project_id=<?php echo $project['id']; ?>'">
                <div class="project-card-image">
                    <img src="<?php echo htmlspecialchars(!empty($project['image_url']) ? $project['image_url'] : 'pictures/project_default.png'); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" onerror="this.src='pictures/project_default.png'">
                    <div class="project-card-overlay"><span>View Details</span></div>
                </div>
                <div class="project-card-content">
                    <div class="project-card-header">
                        <h3 class="project-card-title"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                        <span class="health-badge health-<?php echo $project['health']; ?>" onclick="event.stopPropagation()">
                            <?php echo $project['health_text']; ?>
                            <span class="health-tooltip"><strong><?php echo $health_definitions[$project['health']]; ?></strong><br><br><?php echo htmlspecialchars($project['health_reason']); ?></span>
                        </span>
                    </div>
                    <?php if (!empty($formatted_address)): ?>
                    <div class="project-card-location">📍 <?php echo htmlspecialchars($formatted_address); ?></div>
                    <?php endif; ?>

                    <div class="progress-rings-row">
                        <div class="progress-ring-item">
                            <div class="progress-ring">
                                <svg width="64" height="64">
                                    <circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle>
                                    <circle class="progress-ring-fill order" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $order_offset; ?>"></circle>
                                </svg>
                                <div class="progress-ring-center">
                                    <div class="progress-ring-value"><?php echo round($order_progress); ?>%</div>
                                    <div class="progress-ring-label">ordered</div>
                                </div>
                            </div>
                            <div class="ring-title">Order Progress</div>
                            <div class="ring-subtitle"><?php echo number_format($project['ordered_mw'] ?? 0, 2); ?>/<?php echo number_format($project_size_mw, 2); ?> MW</div>
                        </div>
                        <div class="progress-ring-item">
                            <div class="progress-ring">
                                <svg width="64" height="64">
                                    <circle class="progress-ring-bg" cx="32" cy="32" r="26"></circle>
                                    <circle class="progress-ring-fill delivery" cx="32" cy="32" r="26" stroke-dasharray="<?php echo $circ; ?>" stroke-dashoffset="<?php echo $delivery_offset; ?>"></circle>
                                </svg>
                                <div class="progress-ring-center">
                                    <div class="progress-ring-value"><?php echo round($delivery_progress); ?>%</div>
                                    <div class="progress-ring-label">delivered</div>
                                </div>
                            </div>
                            <div class="ring-title">Delivery Progress</div>
                            <div class="ring-subtitle"><?php echo number_format($project['delivered_mw'] ?? 0, 2); ?>/<?php echo number_format($project_size_mw, 2); ?> MW</div>
                        </div>
                    </div>

                    <div class="project-info-grid">
                        <div class="info-item"><div class="info-label">Project Size</div><div class="info-value"><?php echo number_format($project_size_mw, 2); ?> MW</div></div>
                        <div class="info-item"><div class="info-label">Est. Completion</div><div class="info-value"><?php echo $est_date_display; ?></div></div>
                    </div>

                    <?php if (($project['storage_modules'] ?? 0) > 0): ?>
                    <div class="project-badges">
                        <div class="storage-badge">🏭 <strong><?php echo number_format($project['storage_modules']); ?></strong> in storage (<?php echo $project['storage_percent']; ?>%)</div>
                    </div>
                    <?php endif; ?>

                    <div class="project-card-actions" onclick="event.stopPropagation()">
                        <a href="project_overview.php?project_id=<?php echo $project['id']; ?>" class="btn-view">View Details</a>
                        <div class="dropdown">
                            <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-<?php echo $project['id']; ?>')">⚙️</button>
                            <div id="dropdown-<?php echo $project['id']; ?>" class="dropdown-menu">
                                <a href="edit_project.php?project_id=<?php echo $project['id']; ?>" class="dropdown-item edit">Edit Project</a>
                                <a href="project_close.php?project_id=<?php echo $project['id']; ?>" class="dropdown-item">Close Out</a>
                                <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>', <?php echo $project['id']; ?>)" class="dropdown-item delete">Delete Project</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="add-project-card" onclick="window.location.href='add_project.php'">
                <div class="add-project-icon">+</div>
                <div class="add-project-text">Add New Project</div>
            </div>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e9ecef;">
                <div style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3;">📋</div>
                <h3 style="margin-bottom: 10px; color: #293E4C;">No Active Projects</h3>
                <p style="margin-bottom: 20px; color: #6c757d;">Get started by creating your first project</p>
                <a href="add_project.php" class="add-project-btn" style="display: inline-flex;">+ Add First Project</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Table View -->
    <div class="projects-table-container" id="projects-table">
        <table class="projects-table">
            <thead>
                <tr>
                    <th>Project Name</th>
                    <th>Account</th>
                    <th>Size (MW)</th>
                    <th>Ordered</th>
                    <th>Delivered</th>
                    <th>In Storage</th>
                    <th>Health</th>
                    <th>Target Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project):
                    $project_size_mw = (float)($project['project_size'] ?? 0);
                    $delivery_progress = $project['delivery_progress'] ?? 0;
                    $order_progress = $project['order_progress'] ?? 0;
                    $est_date_display = !empty($project['estimated_completion_date']) ? (new DateTime($project['estimated_completion_date']))->format('M j, Y') : '—';
                ?>
                <tr onclick="window.location.href='project_overview.php?project_id=<?php echo $project['id']; ?>'">
                    <td class="table-project-name"><?php echo htmlspecialchars($project['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($project['account_name']); ?></td>
                    <td><?php echo number_format($project_size_mw, 2); ?></td>
                    <td>
                        <div class="table-progress">
                            <div class="table-progress-bar">
                                <div class="table-progress-fill order" style="width: <?php echo min(100, $order_progress); ?>%;"></div>
                            </div>
                            <span class="table-progress-text order"><?php echo round($order_progress); ?>%</span>
                        </div>
                    </td>
                    <td>
                        <div class="table-progress">
                            <div class="table-progress-bar">
                                <div class="table-progress-fill delivery" style="width: <?php echo min(100, $delivery_progress); ?>%;"></div>
                            </div>
                            <span class="table-progress-text delivery"><?php echo round($delivery_progress); ?>%</span>
                        </div>
                    </td>
                    <td><?php echo number_format($project['storage_modules'] ?? 0); ?></td>
                    <td><span class="health-badge health-<?php echo $project['health']; ?>"><?php echo $project['health_text']; ?></span></td>
                    <td><?php echo $est_date_display; ?></td>
                    <td onclick="event.stopPropagation();">
                        <div class="table-actions">
                            <a href="project_overview.php?project_id=<?php echo $project['id']; ?>" class="table-btn table-btn-view">View</a>
                            <a href="edit_project.php?project_id=<?php echo $project['id']; ?>" class="table-btn table-btn-edit">Edit</a>
                            <button onclick="confirmDelete('<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>', <?php echo $project['id']; ?>)" class="table-btn table-btn-delete">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <h2>⚠️ Confirm Project Deletion</h2>
                <span class="delete-modal-close" onclick="closeDeleteModal()">&times;</span>
            </div>
            
            <div class="delete-warning">
                <h4>Warning: This action cannot be undone!</h4>
                <p>You are about to permanently delete the project <strong id="modalProjectName"></strong> (Account: <span id="modalAccountName"></span>)</p>
            </div>
            
            <div class="deletion-summary">
                <h4>The following data will also be permanently deleted:</h4>
                <div id="deletionSummary">
                    <!-- Dynamically populated -->
                </div>
            </div>
            
            <div class="delete-modal-actions">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="btn-delete-confirm" onclick="executeDelete()">Yes, Delete Everything</button>
            </div>
        </div>
    </div>

</main>

<script>
function setView(view) {
    const grid = document.getElementById('projects-grid');
    const table = document.getElementById('projects-table');
    const btnGrid = document.getElementById('btn-grid');
    const btnTable = document.getElementById('btn-table');

    if (view === 'grid') {
        grid.classList.add('active');
        table.classList.remove('active');
        btnGrid.classList.add('active');
        btnTable.classList.remove('active');
        localStorage.setItem('manageProjectsView', 'grid');
    } else {
        grid.classList.remove('active');
        table.classList.add('active');
        btnGrid.classList.remove('active');
        btnTable.classList.add('active');
        localStorage.setItem('manageProjectsView', 'table');
    }
}

// Restore saved view preference
document.addEventListener('DOMContentLoaded', function() {
    const savedView = localStorage.getItem('manageProjectsView');
    if (savedView) setView(savedView);

    // Initialize pipeline chart
    const ctx = document.getElementById('pipelineChartMini');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['On Track', 'At Risk', 'Behind', 'Completed'],
                datasets: [{
                    data: [<?php echo $health_counts['on_track'].','.$health_counts['at_risk'].','.$health_counts['behind'].','.$health_counts['completed']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#488C9A'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw;
                            }
                        }
                    }
                },
                cutout: '55%'
            }
        });
    }
});
</script>
</body>
</html>
