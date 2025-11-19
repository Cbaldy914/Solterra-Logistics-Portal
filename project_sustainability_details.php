<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';

// Validate project ID
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

/**
 * If role is 'admin' or 'global_admin', user can see any project.
 * Otherwise, we join 'projects.account_id' to 'customer_account_users.account_id'
 * to confirm the user is in that account.
 */
$project_name = '';

if ($role === 'admin' || $role === 'global_admin') {
    // Admin or global_admin => can see any project, just confirm it exists
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular user => check if they belong to the same account as the project
    $sql = "
        SELECT p.project_name
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ?
          AND cau.user_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project or it does not exist.");
}

// --------------------------------------------------------------------------
// NEW FILTER PARAMETERS - Modern approach like view_project.php
// --------------------------------------------------------------------------
$filterColumn  = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$manufacturer_filter = $_GET['manufacturer'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$search_query = $_GET['search'] ?? '';

$dateCondition = "";
$paramTypes    = "i"; 
$params        = [$project_id];

// Date range filter
if ($start_date && $end_date) {
    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    array_push($params, $start_date, $end_date);
} elseif ($start_date) {
    $dateCondition = " AND DATE($filterColumn) >= ?";
    $paramTypes .= "s";
    $params[] = $start_date;
} elseif ($end_date) {
    $dateCondition = " AND DATE($filterColumn) <= ?";
    $paramTypes .= "s";
    $params[] = $end_date;
}

// Status filter
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
}

// Manufacturer filter
$manufacturerCondition = "";
if ($manufacturer_filter !== '') {
    $manufacturerCondition = " AND supplier = ?";
    $paramTypes .= "s";
    $params[] = $manufacturer_filter;
}

// Search filter
$searchCondition = "";
if ($search_query !== '') {
    $searchCondition = " AND (bol_number LIKE ? OR supplier LIKE ?)";
    $paramTypes .= "ss";
    $search_param = "%$search_query%";
    array_push($params, $search_param, $search_param);
}

// "filter" selection (total, ytd, etc.)
$filter        = $_GET['filter'] ?? 'total';
$current_year  = date('Y');
$ytdCondition  = "";

if ($filter === 'ytd') {
    // Filter by YEAR(created_at) = current_year
    $ytdCondition = " AND YEAR(created_at) = ?";
    $paramTypes  .= "i";
    $params[]     = $current_year;
}

// Build final deliveries query with all filters
$sql_deliveries = "
    SELECT *
    FROM deliveries
    WHERE project_id = ?
          $ytdCondition
          $dateCondition
          $statusCondition
          $manufacturerCondition
          $searchCondition
    ORDER BY $filterColumn DESC
";
$stmt_deliveries = $conn->prepare($sql_deliveries);
$stmt_deliveries->bind_param($paramTypes, ...$params);
$stmt_deliveries->execute();
$deliveries_result = $stmt_deliveries->get_result();
$stmt_deliveries->close();

// Summations for sustainability
$total_emissions        = 0.0;
$total_truckloads       = 0;
$total_miles_driven     = 0.0;
$total_fuel_consumption = 0.0;
$total_mws_delivered    = 0;
$deliveries            = [];

$supplier_values = [];
$wattage_values  = [];
$status_values   = [];

while ($delivery = $deliveries_result->fetch_assoc()) {
    $quantity     = (int)($delivery['quantity'] ?? 0);
    $wattage      = (float)($delivery['wattage'] ?? 0);
    $miles_driven = (float)($delivery['miles']   ?? 0);

    // If "Delivered" with miles>0 => count a truckload
    if (in_array($delivery['status_of_delivery'] ?? '', ['Delivered to Project', 'Delivered to Warehouse']) && $miles_driven > 0) {
        $total_truckloads += 1;
    }

    $total_miles_driven     += $miles_driven;
    $fuel_consumption        = $miles_driven * 0.1667; // example factor
    $total_fuel_consumption += $fuel_consumption;
    $emissions              = $fuel_consumption * 10.21; // example factor
    $total_emissions        += $emissions;

    $mws_delivered = ($quantity * $wattage) / 1_000_000; 
    $total_mws_delivered += $mws_delivered;

    $delivery['miles_driven']     = $miles_driven;
    $delivery['fuel_consumption'] = $fuel_consumption;
    $delivery['emissions']        = $emissions;

    $supplier_values[]   = $delivery['supplier'];
    $wattage_values[]    = $delivery['wattage'];
    $status_values[]     = $delivery['status_of_delivery'];

    $deliveries[] = $delivery;
}

// Additional filter logic
if ($filter === 'emissions_per_mw') {
    if ($total_mws_delivered > 0) {
        $emissions_per_mw = $total_emissions / $total_mws_delivered;
    } else {
        $emissions_per_mw = 0;
    }
}
elseif ($filter === 'emissions_vs_average') {
    if ($role === 'admin') {
        $sql_projects = "SELECT id FROM projects";
        $stmt_proj    = $conn->prepare($sql_projects);
        $stmt_proj->execute();
        $projects_result = $stmt_proj->get_result();
        $stmt_proj->close();
    } else {
        // For user (non-admin), we'd check all projects of that user, 
        // but now we need an account-based approach. 
        // Or simply let admin logic handle "average" across all? 
        $sql_projects = "
          SELECT p.id 
          FROM projects p
          JOIN customer_account_users cau ON p.account_id = cau.account_id
          WHERE cau.user_id = ?
        ";
        $stmt_proj = $conn->prepare($sql_projects);
        $stmt_proj->bind_param("i", $user_id);
        $stmt_proj->execute();
        $projects_result = $stmt_proj->get_result();
        $stmt_proj->close();
    }

    $total_user_emissions = 0;
    $total_user_mws       = 0;

    while ($proj = $projects_result->fetch_assoc()) {
        $proj_id = $proj['id'];
        $sql_del = "SELECT * FROM deliveries WHERE project_id = ?";
        $stmt_del = $conn->prepare($sql_del);
        $stmt_del->bind_param("i", $proj_id);
        $stmt_del->execute();
        $del_res = $stmt_del->get_result();
        $stmt_del->close();

        $proj_total_emissions = 0;
        $proj_total_mws       = 0;
        while ($d = $del_res->fetch_assoc()) {
            $qty    = (int)$d['quantity'];
            $watt   = (float)$d['wattage'];
            $miles  = (float)($d['miles'] ?? 0);

            $fuel = $miles * 0.1667;
            $e    = $fuel * 10.21;
            $proj_total_emissions += $e;
            $proj_total_mws       += ($qty * $watt)/1_000_000;
        }
        $total_user_emissions += $proj_total_emissions;
        $total_user_mws       += $proj_total_mws;
    }

    if ($total_user_mws > 0) {
        $average_emissions_per_mw = $total_user_emissions / $total_user_mws;
    } else {
        $average_emissions_per_mw = 0;
    }
    if ($total_mws_delivered > 0) {
        $project_emissions_per_mw = $total_emissions / $total_mws_delivered;
    } else {
        $project_emissions_per_mw = 0;
    }
    $difference = $project_emissions_per_mw - $average_emissions_per_mw;
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] == 1) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sustainability_details.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Supplier', 'Wattage','Quantity','BOL Number','Status','Miles','Fuel Consumption','Emissions'
    ]);
    foreach ($deliveries as $del) {
        fputcsv($output, [
            $del['supplier'] ?? '',
            $del['wattage'] ?? '',
            $del['quantity'] ?? '',
            $del['bol_number'] ?? '',
            $del['status_of_delivery'] ?? '',
            number_format($del['miles_driven'] ?? 0, 2),
            number_format($del['fuel_consumption'] ?? 0, 2),
            number_format($del['emissions'] ?? 0, 2),
        ]);
    }
    fclose($output);
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sustainability Details for <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        /* Header Section */
        .sustainability-tracker-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .sustainability-tracker-header::before {
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
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 140px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }

        .stat-item-total {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.2) 100%);
        }

        .stat-item-emissions {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.2) 100%);
        }

        .stat-item-emissions .stat-number {
            color: #16a34a;
        }

        .stat-item-miles {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.2) 100%);
        }

        .stat-item-miles .stat-number {
            color: #2563eb;
        }

        .stat-item-fuel {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0.2) 100%);
        }

        .stat-item-fuel .stat-number {
            color: #d97706;
        }

        .stat-item-truckloads {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(139, 92, 246, 0.2) 100%);
        }

        .stat-item-truckloads .stat-number {
            color: #7c3aed;
        }

        .stat-number {
            font-size: 1.8em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .filter-title {
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-title i {
            color: #488C9A;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-clear, .btn-apply {
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .btn-clear {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.15) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-clear:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.2) 100%);
            transform: translateY(-1px);
        }

        .btn-apply {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
        }

        .btn-apply:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .filter-group {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .filter-group:has(.date-range-group) {
            grid-column: span 2;
        }

        .filter-label {
            font-weight: 600;
            color: #293E4C;
            font-size: 0.95em;
            margin-bottom: 8px;
        }

        .filter-select, .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            background: white;
            font-size: 0.95em;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .date-range-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* Table Container */
        .table-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .table-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-title {
            font-size: 1.3em;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }

        .table-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-export-header, .btn-columns-header {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .btn-export-header {
            background: rgba(255, 255, 255, 0.95);
            color: #16a34a;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-export-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-columns-header {
            background: rgba(255, 255, 255, 0.95);
            color: #d97706;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-columns-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table thead {
            background: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            border: none;
            background: white;
        }

        table td {
            padding: 16px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.08);
            vertical-align: middle;
        }

        table tbody tr {
            transition: all 0.3s ease;
        }

        table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            transform: translateX(4px);
        }

        /* Column Chooser */
        .column-chooser-content {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: white;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            min-width: 250px;
            max-height: 400px;
            overflow-y: auto;
        }

        .column-chooser-header {
            padding: 16px;
            background: #f8f9fa;
            border-bottom: 1px solid rgba(72, 140, 154, 0.2);
            font-weight: 600;
            color: #293E4C;
        }

        .column-chooser-options {
            padding: 8px 0;
        }

        .column-item {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .column-item:hover {
            background-color: rgba(72, 140, 154, 0.08);
        }

        .column-item label {
            display: flex;
            align-items: center;
            cursor: pointer;
            color: #293E4C;
            font-size: 0.9em;
            width: 100%;
        }

        .column-item input[type=checkbox] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }
        .cost-row {
            display: flex;
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .cost-metric {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            margin: 8px;
            border-radius: 12px;
            text-align: center;
            min-width: 200px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .cost-metric:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        .cost-metric h3 {
            margin: 0 0 10px 0;
            font-weight: 600;
            color: #293E4C;
            font-size: 1rem;
        }
        .cost-metric p {
            margin: 0;
            font-size: 1.4rem;
            font-weight: bold;
            color: #488C9A;
        }
        .legacy-filter-form {
            margin: 15px 20px 20px 20px;
            padding: 15px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #dee2e6;
            width: auto !important;
            max-width: fit-content;
            display: inline-block;
        }
        .legacy-filter-form label {
            margin-right: 15px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 6px;
            transition: background-color 0.2s ease;
            font-size: 0.9em;
        }
        .legacy-filter-form label:hover {
            background-color: #f8f9fa;
        }
        .legacy-filter-form input[type="radio"] {
            margin: 0;
        }
        .time-filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 30px 20px 10px 20px;
            flex-wrap: wrap;
        }
        .time-filters {
            display: flex;
            gap: 10px;
        }
        .time-filters a {
            text-decoration: none;
            padding: 6px 12px;
            background: #eee;
            border-radius: 4px;
            color: #333;
        }
        .time-filters a.active {
            background: #488C9A;
            color: #fff;
        }
        .date-navigation {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-arrow {
            font-weight: bold;
            cursor: pointer;
            background: #eee;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
        }
        .nav-arrow:hover {
            background: #ccc;
        }
        .date-label {
            font-weight: bold;
            font-size: 1.1em;
        }
        .right-filters {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: center;
        }
        @media screen and (max-width: 768px) {
            .mobile-hide {
                display: none !important;
            }
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            box-sizing: border-box;
        }
        .table-responsive table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
            white-space: nowrap;
        }
        tr:hover {
            background: #f1f1f1;
        }
        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            color: #000;
            margin: 20px;
        }
        .back-icon svg {
            width: 24px;
            height: 24px;
            margin-right: 5px;
        }
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        
        /* Dropdown Filters Styling */
        .filters-dropdown, .column-chooser {
            position: relative;
            display: inline-block;
        }

        .filters-btn, .columns-btn {
            background: linear-gradient(135deg, #488C9A, #3A6E7F);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filters-btn:hover, .columns-btn:hover {
            background: linear-gradient(135deg, #3A6E7F, #293E4C);
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        .filters-content, .column-chooser-content {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            padding: 1.5rem;
            min-width: 280px;
            z-index: 1000;
            margin-top: 8px;
            border: 1px solid #DEE2E6;
        }

        .filters-content.show, .column-chooser-content.show {
            display: block;
        }

        .filter-item, .column-item {
            margin-bottom: 1rem;
        }

        .filter-item:last-child, .column-item:last-child {
            margin-bottom: 0;
        }

        .filter-item label, .column-item label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2C3E50;
            font-size: 0.9rem;
        }

        .column-item label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin-bottom: 0;
        }

        .column-item label:hover {
            background: #E8F4F6;
        }

        .column-toggle {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        .export-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            width: 100%;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        #searchInput, #status_filter {
            border: 2px solid #DEE2E6;
            border-radius: 25px;
            padding: 8px 16px;
            background: #FFFFFF;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        #searchInput:focus, #status_filter:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        /* Column visibility controls */
        .column-hidden {
            display: none !important;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            font-size: 4em;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            font-size: 1.5em;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .empty-state p {
            color: #9ca3af;
            margin: 0;
        }

        /* Pagination styles - matching manage_pallets.php */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .pagination-info {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pagination-controls label {
            font-size: 0.9em;
            margin-right: 5px;
            color: #293E4C;
            font-weight: 500;
        }

        .pagination-controls input,
        .pagination-controls select {
            padding: 8px 12px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
        }

        .pagination-controls input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .pagination-controls button {
            padding: 8px 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .pagination-controls button:hover:not(:disabled) {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }

        .pagination-controls button:disabled {
            background: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        #pageInfo {
            color: #293E4C;
            font-weight: 600;
            padding: 0 8px;
        }
    </style>
    <script>
        // Clear filters
        function clearFilters() {
            document.getElementById('filterForm').reset();
            window.location.href = '?project_id=<?php echo $project_id; ?>';
        }

        // Toggle column chooser
        function toggleColumnChooser() {
            const dropdown = document.getElementById('columnChooser');
            dropdown.style.display = dropdown.style.display === 'none' || dropdown.style.display === '' ? 'block' : 'none';
        }

        // Column visibility toggle
        function toggleColumn(columnClass, isVisible) {
            const elements = document.querySelectorAll('.' + columnClass);
            elements.forEach(element => {
                if (isVisible) {
                    element.classList.remove('column-hidden');
                } else {
                    element.classList.add('column-hidden');
                }
            });
        }

        // Initialize column chooser functionality
        document.addEventListener('DOMContentLoaded', function() {
            const columnToggles = document.querySelectorAll('.column-toggle');
            columnToggles.forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const columnClass = this.dataset.column;
                    const isVisible = this.checked;
                    toggleColumn(columnClass, isVisible);
                });
            });

            // Close column chooser on outside click
            document.addEventListener('click', function(e) {
                const columnChooser = document.getElementById('columnChooser');
                if (columnChooser && !e.target.closest('.btn-columns-header') && !columnChooser.contains(e.target)) {
                    columnChooser.style.display = 'none';
                }
            });
        });

        (function() {
            var referrer = document.referrer;
            if (!referrer) return;
            var refAnchor = document.createElement('a');
            refAnchor.href = referrer;

            var curAnchor = document.createElement('a');
            curAnchor.href = window.location.href;

            var refPath = refAnchor.protocol + '//' + refAnchor.host + refAnchor.pathname;
            var curPath = curAnchor.protocol + '//' + curAnchor.host + curAnchor.pathname;
            if (refPath !== curPath) {
                sessionStorage.setItem('backButtonURL', referrer);
            }
        })();
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Sustainability Details',
            'project_id' => (int)$project_id
        ]);
    ?>

    <!-- Header Section -->
    <div class="sustainability-tracker-header">
        <div class="header-content">
            <div class="header-info">
                <h1>Sustainability Details: <?php echo htmlspecialchars($project_name); ?></h1>
                <p class="header-subtitle">Environmental impact analysis and carbon footprint tracking</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-total">
                    <p class="stat-number"><?php echo count($deliveries); ?></p>
                    <p class="stat-label">Total Deliveries</p>
                </div>
                <div class="stat-item stat-item-emissions">
                    <p class="stat-number"><?php echo number_format($total_emissions, 2); ?></p>
                    <p class="stat-label">Total Emissions (kg CO₂)</p>
                </div>
                <div class="stat-item stat-item-miles">
                    <p class="stat-number"><?php echo number_format($total_miles_driven, 2); ?></p>
                    <p class="stat-label">Miles Driven</p>
                </div>
                <div class="stat-item stat-item-fuel">
                    <p class="stat-number"><?php echo number_format($total_fuel_consumption, 2); ?></p>
                    <p class="stat-label">Fuel (gal)</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <form id="filterForm" method="get">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            
            <div class="filter-header">
                <h2 class="filter-title">
                    <i class="fas fa-filter"></i>
                    Filter Deliveries
                </h2>
                <div class="filter-actions">
                    <button type="button" class="btn-clear" onclick="clearFilters()">
                        <i class="fas fa-times"></i>
                        Clear
                    </button>
                    <button type="submit" class="btn-apply">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                </div>
            </div>

            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Delivery Date Range</label>
                    <div class="date-range-group">
                        <input type="date" name="start_date" class="filter-input" placeholder="Start Date" value="<?php echo htmlspecialchars($start_date); ?>">
                        <input type="date" name="end_date" class="filter-input" placeholder="End Date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="manufacturerFilter">Manufacturer</label>
                    <select name="manufacturer" id="manufacturerFilter" class="filter-select">
                        <option value="">All Manufacturers</option>
                        <?php
                        $unique_manufacturers = array_unique(array_column($deliveries, 'supplier'));
                        sort($unique_manufacturers);
                        foreach ($unique_manufacturers as $manufacturer):
                            if (!empty($manufacturer)):
                        ?>
                            <option value="<?php echo htmlspecialchars($manufacturer); ?>" <?php echo $manufacturer_filter == $manufacturer ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($manufacturer); ?>
                            </option>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="statusFilter">Status</label>
                    <select name="status_filter" id="statusFilter" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="On Water" <?php echo $status_filter == 'On Water' ? 'selected' : ''; ?>>On Water</option>
                        <option value="Cleared Customs" <?php echo $status_filter == 'Cleared Customs' ? 'selected' : ''; ?>>Cleared Customs</option>
                        <option value="In Transit to Warehouse" <?php echo $status_filter == 'In Transit to Warehouse' ? 'selected' : ''; ?>>In Transit to Warehouse</option>
                        <option value="Delivered to Warehouse" <?php echo $status_filter == 'Delivered to Warehouse' ? 'selected' : ''; ?>>Delivered to Warehouse</option>
                        <option value="In Transit to Project" <?php echo $status_filter == 'In Transit to Project' ? 'selected' : ''; ?>>In Transit to Project</option>
                        <option value="Delivered to Project" <?php echo $status_filter == 'Delivered to Project' ? 'selected' : ''; ?>>Delivered to Project</option>
                        <option value="Canceled" <?php echo $status_filter == 'Canceled' ? 'selected' : ''; ?>>Canceled</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="searchFilter">Search</label>
                    <input type="text" name="search" id="searchFilter" class="filter-input" placeholder="Search BOL, manufacturer..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </div>
        </form>
    </div>

    <!-- Pagination Controls -->
    <?php if (!empty($deliveries)): ?>
    <div class="pagination-container">
        <div class="pagination-info">
            <span id="paginationInfo">Showing 0 of 0 deliveries</span>
        </div>
        <div class="pagination-controls">
            <label for="itemsPerPage">Show:</label>
            <input type="number" id="itemsPerPage" value="25" min="1" max="500" style="width: 80px;">
            <label>per page</label>
            <button type="button" id="prevPage" disabled>Previous</button>
            <span id="pageInfo">Page 1 of 1</span>
            <button type="button" id="nextPage" disabled>Next</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sustainability Table -->
    <div class="table-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-leaf"></i>
                Environmental Impact
            </h3>
            <div class="table-header-actions">
                <button type="submit" form="filterForm" name="export" value="1" class="btn-export-header">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <div style="position: relative;">
                    <button type="button" class="btn-columns-header" onclick="toggleColumnChooser()">
                        <i class="fas fa-columns"></i>
                        Columns
                    </button>
                    <div class="column-chooser-content" id="columnChooser">
                        <div class="column-chooser-header">Select Columns to Show:</div>
                        <div class="column-chooser-options">
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-supplier" checked> Supplier</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-wattage" checked> Wattage</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-quantity" checked> Quantity</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-bol" checked> BOL Number</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-status" checked> Status</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-miles" checked> Miles Driven</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-fuel" checked> Fuel Consumption</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-emissions" checked> Emissions</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- Deliveries Table -->
    <div class="table-responsive">
        <table id="deliveriesTable">
            <thead>
                <tr>
                    <th class="col-supplier">Supplier</th>
                    <th class="col-wattage">Wattage</th>
                    <th class="col-quantity">Quantity</th>
                    <th class="col-bol">BOL Number</th>
                    <th class="col-status">Status of Delivery</th>
                    <th class="col-miles">Miles Driven</th>
                    <th class="col-fuel">Fuel Consumption</th>
                    <th class="col-emissions">Emissions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($deliveries)): ?>
                <?php foreach ($deliveries as $del): ?>
                    <tr>
                        <td class="col-supplier"><?php echo htmlspecialchars($del['supplier'] ?? ''); ?></td>
                        <td class="col-wattage"><?php echo htmlspecialchars($del['wattage'] ?? ''); ?></td>
                        <td class="col-quantity"><?php echo htmlspecialchars($del['quantity'] ?? ''); ?></td>
                        <td class="col-bol"><?php echo htmlspecialchars($del['bol_number'] ?? ''); ?></td>
                        <td class="col-status"><?php echo htmlspecialchars($del['status_of_delivery'] ?? ''); ?></td>
                        <td class="col-miles"><?php echo number_format($del['miles_driven'] ?? 0, 2); ?></td>
                        <td class="col-fuel"><?php echo number_format($del['fuel_consumption'] ?? 0, 2); ?> gal</td>
                        <td class="col-emissions"><?php echo number_format($del['emissions'] ?? 0, 2); ?> kg CO₂</td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align: center; padding: 40px; color: #6c757d;">
                    <div style="font-size: 1.1em; margin-bottom: 10px;">🌱 No sustainability data available</div>
                    <div style="font-size: 0.9em; line-height: 1.4;">
                        <?php if (!empty($status_filter) || $time_filter !== 'all'): ?>
                            No deliveries match your current filters. Try adjusting your time period or status filter.
                        <?php else: ?>
                            No deliveries have been recorded for this project yet.<br>
                            Sustainability metrics will appear here once deliveries are added to the system.
                        <?php endif; ?>
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    function searchTable() {
        var input = document.getElementById("searchInput");
        if (!input) return;
        var filter = input.value.toLowerCase();
        var table = document.getElementById("deliveriesTable");
        var trs = table.getElementsByTagName("tr");

        for (var i=1; i<trs.length; i++) {
            var tds = trs[i].getElementsByTagName("td");
            var show = false;
            for (var j=0; j<tds.length; j++) {
                var txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    show = true;
                    break;
                }
            }
            trs[i].style.display = show ? "" : "none";
        }
    }

    // Toggle filters dropdown
    function toggleFilters() {
        const dropdown = document.getElementById('filtersDropdown');
        const arrow = document.getElementById('filter-arrow');
        const columnDropdown = document.getElementById('columnChooser');
        
        // Close column chooser if open
        if (columnDropdown) {
            columnDropdown.classList.remove('show');
            document.getElementById('column-arrow').textContent = '▼';
        }
        
        dropdown.classList.toggle('show');
        arrow.textContent = dropdown.classList.contains('show') ? '▲' : '▼';
    }

    // Toggle column chooser
    function toggleColumnChooser() {
        const dropdown = document.getElementById('columnChooser');
        const arrow = document.getElementById('column-arrow');
        const filtersDropdown = document.getElementById('filtersDropdown');
        
        // Close filters if open
        if (filtersDropdown) {
            filtersDropdown.classList.remove('show');
            document.getElementById('filter-arrow').textContent = '▼';
        }
        
        dropdown.classList.toggle('show');
        arrow.textContent = dropdown.classList.contains('show') ? '▲' : '▼';
    }

    // Column visibility toggle
    function toggleColumn(columnClass, isVisible) {
        const elements = document.querySelectorAll('.' + columnClass);
        elements.forEach(element => {
            if (isVisible) {
                element.classList.remove('column-hidden');
            } else {
                element.classList.add('column-hidden');
            }
        });
    }

    // Click outside handler
    document.addEventListener('click', function(e) {
        // Close column chooser
        if (!e.target.closest('.btn-columns-header') && !e.target.closest('.column-chooser-content')) {
            const columnChooser = document.getElementById('columnChooser');
            if (columnChooser && columnChooser.classList.contains('show')) {
                columnChooser.classList.remove('show');
            }
        }
    });

    // Initialize column chooser functionality
    document.addEventListener('DOMContentLoaded', function() {
        const columnToggles = document.querySelectorAll('.column-toggle');
        columnToggles.forEach(toggle => {
            toggle.addEventListener('change', function() {
                const columnClass = this.dataset.column;
                const isVisible = this.checked;
                toggleColumn(columnClass, isVisible);
            });
        });

        // Initialize pagination
        initializePagination();
    });

    // Pagination logic
    let currentPage = 1;
    let itemsPerPage = 25;
    let allDeliveryRows = [];

    function initializePagination() {
        const table = document.getElementById('deliveriesTable');
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        allDeliveryRows = Array.from(tbody.querySelectorAll('tr'));
        
        const itemsPerPageInput = document.getElementById('itemsPerPage');
        const prevButton = document.getElementById('prevPage');
        const nextButton = document.getElementById('nextPage');
        
        if (itemsPerPageInput) {
            itemsPerPageInput.addEventListener('change', function() {
                itemsPerPage = Math.min(Math.max(1, parseInt(this.value) || 25), 500);
                this.value = itemsPerPage;
                currentPage = 1;
                updatePagination();
            });
        }
        
        if (prevButton) {
            prevButton.addEventListener('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    updatePagination();
                }
            });
        }
        
        if (nextButton) {
            nextButton.addEventListener('click', function() {
                const maxPages = Math.ceil(allDeliveryRows.length / itemsPerPage);
                if (currentPage < maxPages) {
                    currentPage++;
                    updatePagination();
                }
            });
        }
        
        updatePagination();
    }

    function updatePagination() {
        const totalItems = allDeliveryRows.length;
        const maxPages = Math.ceil(totalItems / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        
        // Hide all rows
        allDeliveryRows.forEach(row => {
            row.style.display = 'none';
        });
        
        // Show only current page rows
        allDeliveryRows.slice(startIndex, endIndex).forEach(row => {
            row.style.display = '';
        });
        
        // Update pagination info
        const paginationInfo = document.getElementById('paginationInfo');
        const pageInfo = document.getElementById('pageInfo');
        const prevButton = document.getElementById('prevPage');
        const nextButton = document.getElementById('nextPage');
        
        if (paginationInfo) {
            const showing = Math.min(endIndex, totalItems);
            const displayStart = totalItems > 0 ? startIndex + 1 : 0;
            paginationInfo.textContent = `Showing ${displayStart}-${showing} of ${totalItems} deliveries`;
        }
        
        if (pageInfo) {
            pageInfo.textContent = `Page ${Math.max(1, currentPage)} of ${Math.max(1, maxPages)}`;
        }
        
        if (prevButton) {
            prevButton.disabled = currentPage <= 1;
        }
        
        if (nextButton) {
            nextButton.disabled = currentPage >= maxPages || totalItems === 0;
        }
    }
    </script>
</main>
</body>
</html>

