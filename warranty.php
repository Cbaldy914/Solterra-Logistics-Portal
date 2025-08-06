<?php
session_name("logistics_session");
session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for a project_id parameter; if missing, exit.
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = (int)$_GET['project_id'];

// --------------------------------------------------------------------
// Database connection
// --------------------------------------------------------------------
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// --------------------------------------------------------------------
// Fetch project name
// --------------------------------------------------------------------
$stmt_proj = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt_proj->bind_param("i", $project_id);
$stmt_proj->execute();
$stmt_proj->bind_result($project_name);
$stmt_proj->fetch();
$stmt_proj->close();

if (!$project_name) {
    die("Project not found.");
}

// --------------------------------------------------------------------
// TIME FILTER LOGIC (all/day/week/month)
// We'll filter by COALESCE(w.new_delivery_date, w.delivery_date)
// --------------------------------------------------------------------
$filterColumn = "COALESCE(w.new_delivery_date, w.delivery_date)";
$time_filter  = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$ref_date     = isset($_GET['ref_date'])    ? $_GET['ref_date']    : date('Y-m-d');

$dateCondition = "";
$paramTypes    = "i"; // we always bind $project_id first
$params        = [$project_id];

// For date navigation labeling
$dateLabel = "All Warranties";
$prev_date = $ref_date;
$next_date = $ref_date;

if ($time_filter === 'day') {
    $dateCondition = " AND DATE($filterColumn) = ?";
    $paramTypes   .= "s";
    $params[]      = $ref_date;

    $dateLabel = date('F j, Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($ref_date . " -1 day"));
    $next_date = date('Y-m-d', strtotime($ref_date . " +1 day"));
}
elseif ($time_filter === 'week') {
    $timestamp   = strtotime($ref_date);
    $dayOfWeek   = date('w', $timestamp);
    $startOfWeek = date('Y-m-d', strtotime("-{$dayOfWeek} days", $timestamp));
    $endOfWeek   = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfWeek;
    $params[]      = $endOfWeek;

    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime($startOfWeek . " -7 days"));
    $next_date = date('Y-m-d', strtotime($startOfWeek . " +7 days"));
}
elseif ($time_filter === 'month') {
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth   = date('Y-m-t', strtotime($ref_date));

    $dateCondition = " AND DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes   .= "ss";
    $params[]      = $startOfMonth;
    $params[]      = $endOfMonth;

    $dateLabel = date('F Y', strtotime($ref_date));
    $prev_date = date('Y-m-d', strtotime($startOfMonth . " -1 month"));
    $next_date = date('Y-m-d', strtotime($startOfMonth . " +1 month"));
}

// --------------------------------------------------------------------
// STATUS FILTER
// --------------------------------------------------------------------
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$statusCondition = "";
if (!empty($status_filter)) {
    $statusCondition = " AND w.status = ?";
    $paramTypes     .= "s";
    $params[]        = $status_filter;
}

// --------------------------------------------------------------------
// CSV EXPORT
// --------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="warranty_claims_export.csv"');

    $out = fopen('php://output', 'w');
    // CSV header
    fputcsv($out, [
        'BOL#',
        'Pallet ID',
        'Wattage',
        'Status',
        'Issue Type',
        'Expected Qty',
        'Actual Qty',
        'Damaged Qty',
        'Accepted Qty',
        'Original Delivery Date',
        'New Delivery Date',
        'Notes',
        'Manufacturer Notes'
    ]);

    // Build export query with project_id directly
    $sql_export = "
      SELECT
        w.bol_number,
        w.status,
        w.delivery_date AS original_delivery_date,
        w.new_delivery_date,
        w.manufacturer_notes,
        w.notes,
        w.pallet_id,
        w.issue_type,
        w.expected_quantity,
        w.actual_quantity,
        w.damaged_quantity,
        w.accepted_quantity,
        ip.pallet_identifier,
        ip.wattage
      FROM warranty_claims w
      JOIN site_scheduling s ON w.scheduling_id = s.id
      LEFT JOIN inventory_pallets ip ON w.pallet_id = ip.id
      WHERE s.project_id = ?
            $dateCondition
            $statusCondition
      ORDER BY w.id DESC
    ";
    $stmt_exp = $conn->prepare($sql_export);
    $stmt_exp->bind_param($paramTypes, ...$params);
    $stmt_exp->execute();
    $res_exp = $stmt_exp->get_result();

    // Helper functions for export
    function formatDate($dtStr) {
        if (empty($dtStr) || $dtStr === '0000-00-00') return '';
        $dt = new DateTime($dtStr);
        return $dt->format('m-d-Y');
    }

    if ($res_exp) {
        while ($row_exp = $res_exp->fetch_assoc()) {
            $bol           = $row_exp['bol_number'] ?? '';
            $palletId      = $row_exp['pallet_identifier'] ?: ('ID: ' . $row_exp['pallet_id']);
            $wattage       = $row_exp['wattage'] ?? '';
            $status        = $row_exp['status'] ?? '';
            $issueType     = $row_exp['issue_type'] ?? '';
            $expectedQty   = $row_exp['expected_quantity'] ?? '';
            $actualQty     = $row_exp['actual_quantity'] ?? '';
            $damagedQty    = $row_exp['damaged_quantity'] ?? '';
            $acceptedQty   = $row_exp['accepted_quantity'] ?? '';
            $origDate      = formatDate($row_exp['original_delivery_date']);
            $newDate       = formatDate($row_exp['new_delivery_date']);
            $notes         = $row_exp['notes'] ?? '';
            $manNotes      = $row_exp['manufacturer_notes'] ?? '';

            fputcsv($out, [
                $bol,
                $palletId,
                $wattage . 'W',
                $status,
                ucfirst(str_replace('_', ' ', $issueType)),
                $expectedQty,
                $actualQty,
                $damagedQty,
                $acceptedQty,
                $origDate,
                $newDate,
                $notes,
                $manNotes
            ]);
        }
    }
    fclose($out);
    $stmt_exp->close();
    $conn->close();
    exit();
}

// --------------------------------------------------------------------
// Retrieve warranty claims with updated query using project_id directly
// --------------------------------------------------------------------
$sql = "
SELECT
  w.id AS warranty_id,
  w.bol_number,
  w.status,
  w.delivery_date AS original_delivery_date,
  w.new_delivery_date,
  w.manufacturer_notes,
  w.notes,
  w.pictures,
  w.pallet_id,
  w.issue_type,
  w.expected_quantity,
  w.actual_quantity,
  w.damaged_quantity,
  w.accepted_quantity,
  ip.pallet_identifier,
  ip.wattage,
  s.wattage as scheduling_wattage
FROM warranty_claims w
JOIN site_scheduling s ON w.scheduling_id = s.id
LEFT JOIN inventory_pallets ip ON w.pallet_id = ip.id
WHERE s.project_id = ?
      $dateCondition
      $statusCondition
ORDER BY w.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
$stmt->close();
$conn->close();

// --------------------------------------------------------------------
// HELPER FUNCTIONS
// --------------------------------------------------------------------
function formatDate($dtStr) {
    if (empty($dtStr) || $dtStr === '0000-00-00') {
        return '';
    }
    $dt = new DateTime($dtStr);
    return $dt->format('m-d-Y');
}
function buildIssueDescription($row) {
    $descLines = [];
    $wattage = $row['wattage'] ?? '';
    
    // Handle damage issues
    if ($row['damaged_quantity'] > 0) {
        $descLines[] = $row['damaged_quantity'] . " modules damaged ({$wattage}W)";
    }
    
    // Handle quantity discrepancies
    $expected = (int)($row['expected_quantity'] ?? 0);
    $actual = (int)($row['actual_quantity'] ?? 0);
    if ($expected != $actual) {
        $diff = $actual - $expected;
        if ($diff < 0) {
            $short = abs($diff);
            $descLines[] = "$short modules short ({$wattage}W)";
        } elseif ($diff > 0) {
            $descLines[] = "$diff modules overage ({$wattage}W)";
        }
    }
    
    return !empty($descLines) ? implode("; ", $descLines) : "No specific issues reported";
}
function getModulesImpactedCount($row) {
    // Return total modules impacted (damaged + quantity discrepancy)
    $damaged = (int)($row['damaged_quantity'] ?? 0);
    $expected = (int)($row['expected_quantity'] ?? 0);
    $actual = (int)($row['actual_quantity'] ?? 0);
    $discrepancy = abs($actual - $expected);
    
    // Use the higher of damaged count or total discrepancy to avoid double counting
    return max($damaged, $discrepancy);
}

function getMWFromWarrantyClaim($row) {
    $modulesImpacted = getModulesImpactedCount($row);
    $wattage = (float)($row['wattage'] ?? 0);
    
    if ($modulesImpacted > 0 && $wattage > 0) {
        return ($modulesImpacted * $wattage) / 1000000;
    }
    return 0.0;
}

// --------------------------------------------------------------------
// Metrics calculation logic
// --------------------------------------------------------------------
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'claim';

// Metric counters
$total_warranty_claims = 0;
$total_pending_claims  = 0;
$total_resolved_claims = 0;
$total_rejected_claims = 0;

$total_warranty_mw      = 0.0;
$total_pending_mw       = 0.0;
$total_resolved_mw      = 0.0;
$total_rejected_mw      = 0.0;

$total_warranty_modules = 0;
$total_pending_modules  = 0;
$total_resolved_modules = 0;
$total_rejected_modules = 0;

foreach ($rows as $row) {
    $status = strtolower($row['status'] ?? '');
    if ($filter === 'claim') {
        $value = 1; // each row = 1 claim (now one per pallet)
    }
    elseif ($filter === 'mw') {
        $value = getMWFromWarrantyClaim($row);
    }
    elseif ($filter === 'module') {
        $value = getModulesImpactedCount($row);
    } else {
        $value = 1;
    }

    // Overall
    if ($filter === 'claim') {
        $total_warranty_claims += $value;
    }
    elseif ($filter === 'mw') {
        $total_warranty_mw += $value;
    }
    elseif ($filter === 'module') {
        $total_warranty_modules += $value;
    }

    // Status-based
    if ($status === 'pending') {
        if ($filter === 'claim') $total_pending_claims += $value;
        elseif ($filter === 'mw') $total_pending_mw += $value;
        elseif ($filter === 'module') $total_pending_modules += $value;
    }
    if ($status === 'resolved') {
        if ($filter === 'claim') $total_resolved_claims += $value;
        elseif ($filter === 'mw') $total_resolved_mw += $value;
        elseif ($filter === 'module') $total_resolved_modules += $value;
    }
    if ($status === 'rejected') {
        if ($filter === 'claim') $total_rejected_claims += $value;
        elseif ($filter === 'mw') $total_rejected_mw += $value;
        elseif ($filter === 'module') $total_rejected_modules += $value;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> - Warranty Claims</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .cost-overview {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            margin-bottom: 50px;
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
        .cost-metric--total {
            max-width: 400px;
            background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
            color: white;
        }
        .cost-metric--total h3,
        .cost-metric--total p {
            color: white;
        }
        .cost-metric--efficiency {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border-color: #b8dabd;
        }
        .cost-metric--efficiency h3 {
            color: #155724;
        }
        .cost-metric--efficiency p {
            color: #28a745;
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
        .table-container {
            overflow-x: auto;
            width: 100%;
            margin-bottom: 20px;
        }
        table {
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
        .back-icon {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            margin: 10px;
            color: #333;
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

        /* Enhanced warranty-specific styles */
        
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

        /* Enhanced Documents Dropdown */
        .docs-dropdown {
            position: relative;
            display: inline-block;
        }

        .docs-icon {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: linear-gradient(135deg, #17a2b8, #20c997);
            color: white;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .docs-icon:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }

        .docs-icon img {
            width: 16px;
            height: 16px;
            filter: brightness(0) invert(1);
        }

        .docs-dropdown-content {
            display: none;
            position: fixed;
            background: #FFFFFF;
            border-radius: 12px;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            padding: 1rem;
            min-width: 250px;
            z-index: 9999;
            margin-top: 8px;
            border: 1px solid #DEE2E6;
        }

        .docs-dropdown-content.show {
            display: block;
        }

        .docs-dropdown-content a {
            display: block;
            padding: 8px 12px;
            color: #488C9A;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            margin-bottom: 4px;
        }

        .docs-dropdown-content a:hover {
            background: #E8F4F6;
            transform: translateX(4px);
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: linear-gradient(135deg, #ffc107, #ffda6a);
            color: #856404;
        }

        .status-resolved {
            background: linear-gradient(135deg, #28a745, #5cb85c);
            color: white;
        }

        .status-rejected {
            background: linear-gradient(135deg, #dc3545, #f86c7b);
            color: white;
        }

        /* Enhanced Table Design with Borders */
        #warrantyTable {
            width: 100%;
            margin: 0 auto 20px auto;
            background: #FFFFFF;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            border-collapse: collapse;
            border: 2px solid #DEE2E6;
        }

        #warrantyTable thead {
            background: linear-gradient(135deg, #293E4C, #3A6E7F);
        }

        #warrantyTable th {
            padding: 1.5rem 1rem;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }

        #warrantyTable tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid #DEE2E6;
        }

        #warrantyTable tbody tr:hover {
            background: linear-gradient(135deg, #E8F4F6 0%, rgba(72, 140, 154, 0.05) 100%);
        }

        #warrantyTable td {
            padding: 1.25rem 1rem;
            border: 1px solid #DEE2E6;
            vertical-align: middle;
            font-size: 0.9rem;
            color: #2C3E50;
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





        /* Status Badges */
        .status-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: linear-gradient(135deg, #ffc107, #ffda6a);
            color: #856404;
        }

        .status-resolved {
            background: linear-gradient(135deg, #28a745, #5cb85c);
            color: white;
        }

        .status-rejected {
            background: linear-gradient(135deg, #dc3545, #f86c7b);
            color: white;
        }



        .no-data-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        /* Mobile Responsiveness */
        @media screen and (max-width: 768px) {
            h1 {
                font-size: 2rem;
            }

            .metrics-overview {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 0 1rem;
            }

            .time-filter-header {
                flex-direction: column;
                gap: 1rem;
                margin: 2rem 1rem;
                padding: 1.5rem;
            }

            .date-navigation {
                order: -1;
            }

            .right-filters {
                justify-content: center;
                width: 100%;
            }

            .filters-content, .column-chooser-content {
                right: auto;
                left: 0;
                min-width: 250px;
            }

            #warrantyTable {
                font-size: 0.8rem;
            }

            #warrantyTable th,
            #warrantyTable td {
                padding: 0.75rem 0.5rem;
            }
            
            /* Hide certain columns on mobile by default */
            .col-manufacturer-notes,
            .col-notes,
            .col-new-date,
            .col-original-date {
                display: none;
            }
        }

        /* Loading States */
        .loading {
            background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--bg-tertiary) 50%, var(--bg-secondary) 75%);
            background-size: 200px 100%;
        }

        /* Micro-interactions */
        .metric-box:active {
            transform: translateY(-4px) scale(0.98);
        }

        .time-filters a:active {
            transform: scale(0.95);
        }

        .nav-arrow:active {
            transform: scale(0.9);
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Overview</a>
        <span class="separator">&raquo;</span>
        <span>Warranty Claims</span>
    </div>

    <h1>Warranty Claims for <?php echo htmlspecialchars($project_name); ?></h1>

    <!-- Legacy filter form (Claim/MW/Module) -->
    <form method="GET" class="legacy-filter-form">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        <input type="hidden" name="time_filter" value="<?php echo htmlspecialchars($time_filter); ?>">
        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">

        <label>
            <input type="radio" name="filter" value="claim"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'claim') echo 'checked'; ?>>
            📋 Claim Count
        </label>
        <label>
            <input type="radio" name="filter" value="mw"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'mw') echo 'checked'; ?>>
            ⚡ Megawatts
        </label>
        <label>
            <input type="radio" name="filter" value="module"
                   onchange="this.form.submit();"
                   <?php if ($filter === 'module') echo 'checked'; ?>>
            🔋 Module Count
        </label>
    </form>

    <!-- WARRANTY OVERVIEW -->
    <div class="cost-overview">
        <div class="cost-row">
            <div class="cost-metric cost-metric--total">
                <h3>🛡️ Total Warranty Claims</h3>
                <p>
                    <?php 
                    if ($filter === 'claim') {
                        echo $total_warranty_claims;
                    } elseif ($filter === 'mw') {
                        echo number_format($total_warranty_mw, 2) . " MW";
                    } elseif ($filter === 'module') {
                        echo number_format($total_warranty_modules);
                    }
                    ?>
                </p>
            </div>
        </div>
        <div class="cost-row">
            <div class="cost-metric">
                <h3>⏳ Pending Claims</h3>
                <p>
                    <?php 
                    if ($filter === 'claim') {
                        echo $total_pending_claims;
                    } elseif ($filter === 'mw') {
                        echo number_format($total_pending_mw, 2) . " MW";
                    } elseif ($filter === 'module') {
                        echo number_format($total_pending_modules);
                    }
                    ?>
                </p>
            </div>
            <div class="cost-metric">
                <h3>✅ Resolved Claims</h3>
                <p>
                    <?php 
                    if ($filter === 'claim') {
                        echo $total_resolved_claims;
                    } elseif ($filter === 'mw') {
                        echo number_format($total_resolved_mw, 2) . " MW";
                    } elseif ($filter === 'module') {
                        echo number_format($total_resolved_modules);
                    }
                    ?>
                </p>
            </div>
            <div class="cost-metric">
                <h3>❌ Rejected Claims</h3>
                <p>
                    <?php 
                    if ($filter === 'claim') {
                        echo $total_rejected_claims;
                    } elseif ($filter === 'mw') {
                        echo number_format($total_rejected_mw, 2) . " MW";
                    } elseif ($filter === 'module') {
                        echo number_format($total_rejected_modules);
                    }
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- TIME FILTER HEADER -->
    <div class="time-filter-header">
        <div class="time-filters">
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=all&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='all')?'active':''; ?>">
               All
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=day&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='day')?'active':''; ?>">
               Day
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=week&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='week')?'active':''; ?>">
               Week
            </a>
            <a href="?project_id=<?php echo $project_id; ?>&time_filter=month&ref_date=<?php echo urlencode($ref_date); ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>"
               class="<?php echo ($time_filter==='month')?'active':''; ?>">
               Month
            </a>
        </div>

        <div class="date-navigation">
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &larr;
                </button>
            <?php endif; ?>
            <span class="date-label"><?php echo $dateLabel; ?></span>
            <?php if ($time_filter !== 'all'): ?>
                <button type="button" class="nav-arrow"
                        onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>&status_filter=<?php echo urlencode($status_filter); ?>&filter=<?php echo urlencode($filter); ?>'">
                    &rarr;
                </button>
            <?php endif; ?>
        </div>

        <div class="right-filters">
            <!-- Filters Dropdown -->
            <div class="filters-dropdown">
                <button type="button" class="filters-btn" onclick="toggleFilters()">
                    🔧 Filters <span id="filter-arrow">▼</span>
                </button>
                <div class="filters-content" id="filtersDropdown">
                    <div class="filter-item">
                        <label for="searchInput">🔍 Search:</label>
                        <input type="text" id="searchInput" placeholder="Search claims..." onkeyup="searchTable()">
                    </div>
                    
                    <form method="get" action="" id="filterForm">
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <input type="hidden" name="time_filter" value="<?php echo $time_filter; ?>">
                        <input type="hidden" name="ref_date" value="<?php echo htmlspecialchars($ref_date); ?>">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

                        <div class="filter-item">
                            <label for="status_filter">Status:</label>
                            <select name="status_filter" id="status_filter" onchange="this.form.submit()">
                                <option value="">All Statuses</option>
                                <option value="pending"   <?php if ($status_filter==='pending')   echo 'selected'; ?>>Pending</option>
                                <option value="resolved"  <?php if ($status_filter==='resolved')  echo 'selected'; ?>>Resolved</option>
                                <option value="rejected"  <?php if ($status_filter==='rejected')  echo 'selected'; ?>>Rejected</option>
                                <option value="in progress" <?php if ($status_filter==='in progress') echo 'selected'; ?>>In Progress</option>
                            </select>
                        </div>

                        <div class="filter-item">
                            <button type="submit" name="action" value="export_csv" class="export-btn">📥 Export CSV</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Column Chooser for All Roles -->
            <div class="column-chooser">
                <button type="button" class="columns-btn" onclick="toggleColumnChooser()">
                    📋 Columns <span id="column-arrow">▼</span>
                </button>
                <div class="column-chooser-content" id="columnChooser">
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-bol" checked> BOL Number</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-pallet" checked> Pallet</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-status" checked> Status</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-issue-type" checked> Issue Type</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-expected" checked> Expected</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-received" checked> Received</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-damaged" checked> Damaged</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-accepted" checked> Accepted</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-original-date" checked> Original Date</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-new-date"> New Date</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-notes"> Notes</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-manufacturer-notes"> Manufacturer Notes</label>
                    </div>
                    <div class="column-item">
                        <label><input type="checkbox" class="column-toggle" data-column="col-documents" checked> Documents</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revolutionary Warranty Table -->
    <table id="warrantyTable">
        <thead>
            <tr>
                <th class="col-bol">BOL Number</th>
                <th class="col-pallet">Pallet</th>
                <th class="col-status">Status</th>
                <th class="col-issue-type">Issue Type</th>
                <th class="col-expected">Expected</th>
                <th class="col-received">Received</th>
                <th class="col-damaged">Damaged</th>
                <th class="col-accepted">Accepted</th>
                <th class="col-original-date">Original Date</th>
                <th class="col-new-date">New Date</th>
                <th class="col-notes">Notes</th>
                <th class="col-manufacturer-notes">Manufacturer Notes</th>
                <th class="col-documents">Documents</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $r):
                    $bol           = $r['bol_number'] ?? '';
                    $palletId      = $r['pallet_identifier'] ?: ('ID: ' . $r['pallet_id']);
                    $status        = strtolower($r['status'] ?? '');
                    $issueType     = $r['issue_type'] ?? '';
                    $expectedQty   = $r['expected_quantity'] ?? '';
                    $actualQty     = $r['actual_quantity'] ?? '';
                    $damagedQty    = $r['damaged_quantity'] ?? '';
                    $acceptedQty   = $r['accepted_quantity'] ?? '';
                    $wattage       = $r['wattage'] ?? '';
                    $manNotes      = $r['manufacturer_notes'] ?? '';
                    $originalDate  = formatDate($r['original_delivery_date']);
                    $newDate       = formatDate($r['new_delivery_date']);
                    $notes         = $r['notes'] ?? '';
                    
                    // Parse pictures (JSON)
                    $picsJson = $r['pictures'] ?? '';
                    $picsArr  = [];
                    if (!empty($picsJson)) {
                        $tmp = json_decode($picsJson, true);
                        if (is_array($tmp)) {
                            $picsArr = $tmp;
                        }
                    }
                    $warrantyId = (int)($r['warranty_id'] ?? 0);
                    
                    // Status badge class
                    $statusClass = 'status-' . str_replace(' ', '-', $status);
                ?>
                <tr>
                    <td class="col-bol"><strong><?php echo htmlspecialchars($bol); ?></strong></td>
                    <td class="col-pallet">
                        <strong><?php echo htmlspecialchars($palletId); ?></strong><br>
                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($wattage); ?>W</small>
                    </td>
                    <td class="col-status">
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </td>
                    <td class="col-issue-type">
                        <span style="padding: 4px 12px; background: #f8f9fa; border-radius: 20px; font-size: 0.85rem; font-weight: 600; color: var(--text-primary);">
                            <?php echo ucfirst(str_replace('_', ' ', $issueType)); ?>
                        </span>
                    </td>
                    <td class="col-expected" style="text-align: center; font-weight: 600;"><?php echo htmlspecialchars($expectedQty); ?></td>
                    <td class="col-received" style="text-align: center; font-weight: 600; <?php echo ($actualQty < $expectedQty) ? 'color: #dc3545;' : (($actualQty > $expectedQty) ? 'color: #ffc107;' : 'color: #28a745;'); ?>">
                        <?php echo htmlspecialchars($actualQty); ?>
                    </td>
                    <td class="col-damaged" style="text-align: center; font-weight: 600; <?php echo ($damagedQty > 0) ? 'color: #dc3545;' : ''; ?>">
                        <?php echo $damagedQty > 0 ? htmlspecialchars($damagedQty) : '—'; ?>
                    </td>
                    <td class="col-accepted" style="text-align: center; font-weight: 600; color: #28a745;"><?php echo htmlspecialchars($acceptedQty); ?></td>
                    <td class="col-original-date"><?php echo $originalDate ? htmlspecialchars($originalDate) : '<span style="color: var(--text-muted);">—</span>'; ?></td>
                    <td class="col-new-date"><?php echo $newDate ? htmlspecialchars($newDate) : '<span style="color: var(--text-muted);">—</span>'; ?></td>
                    <td class="col-notes"><?php echo $notes ? htmlspecialchars($notes) : '<span style="color: var(--text-muted);">—</span>'; ?></td>
                    <td class="col-manufacturer-notes"><?php echo $manNotes ? htmlspecialchars($manNotes) : '<span style="color: var(--text-muted);">—</span>'; ?></td>
                    <td class="col-documents">
                        <?php if (!empty($picsArr)): ?>
                            <div class="docs-dropdown">
                                <span class="docs-icon" onclick="toggleDropdown(<?php echo $warrantyId; ?>)">
                                    <img src="pictures/folder_icon.jpg" alt="📁"> 
                                    <?php echo count($picsArr); ?> files
                                </span>
                                <div class="docs-dropdown-content" id="dropdown_<?php echo $warrantyId; ?>">
                                    <?php foreach ($picsArr as $imgPath):
                                        $fileName = basename($imgPath);
                                    ?>
                                        <a href="<?php echo htmlspecialchars($imgPath); ?>" target="_blank">
                                            📄 <?php echo htmlspecialchars($fileName); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">No documents</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="13" class="no-data">
                        <div class="no-data-icon">🔍</div>
                        <h3>No warranty claims found</h3>
                        <p>There are no warranty claims matching your current filters.</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</main>

<script>
// Table search functionality
function searchTable() {
    const input = document.getElementById("searchInput");
    if (!input) return;
    
    const filter = input.value.toLowerCase();
    const table = document.getElementById("warrantyTable");
    const rows = table.querySelectorAll("tbody tr");
    
    rows.forEach((row) => {
        const cells = row.getElementsByTagName("td");
        let show = false;
        
        for (let cell of cells) {
            const text = cell.textContent || cell.innerText;
            if (text.toLowerCase().includes(filter)) {
                show = true;
                break;
            }
        }
        
        row.style.display = show ? "" : "none";
    });
}

// Toggle dropdown functionality
function toggleDropdown(warrantyId) {
    const dropdown = document.getElementById('dropdown_' + warrantyId);
    if (!dropdown) return;
    
    // Close all other dropdowns
    const allDropdowns = document.querySelectorAll('.docs-dropdown-content');
    allDropdowns.forEach(dd => {
        if (dd !== dropdown) {
            dd.classList.remove('show');
        }
    });
    
    // Position dropdown relative to the button
    if (!dropdown.classList.contains('show')) {
        const button = dropdown.previousElementSibling;
        const rect = button.getBoundingClientRect();
        dropdown.style.top = (rect.bottom + 5) + 'px';
        dropdown.style.left = Math.max(10, rect.right - 250) + 'px'; // Ensure it doesn't go off screen
    }
    
    // Toggle current dropdown
    dropdown.classList.toggle('show');
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
    // Close document dropdowns
    if (!e.target.closest('.docs-dropdown')) {
        const allDropdowns = document.querySelectorAll('.docs-dropdown-content.show');
        allDropdowns.forEach(dropdown => {
            dropdown.classList.remove('show');
        });
    }
    
    // Close filters dropdown
    if (!e.target.closest('.filters-dropdown')) {
        const filtersDropdown = document.getElementById('filtersDropdown');
        if (filtersDropdown && filtersDropdown.classList.contains('show')) {
            filtersDropdown.classList.remove('show');
            document.getElementById('filter-arrow').textContent = '▼';
        }
    }
    
    // Close column chooser
    if (!e.target.closest('.column-chooser')) {
        const columnChooser = document.getElementById('columnChooser');
        if (columnChooser && columnChooser.classList.contains('show')) {
            columnChooser.classList.remove('show');
            document.getElementById('column-arrow').textContent = '▼';
        }
    }
});

// Add loading states for form submissions and initialize column chooser
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '⏳ Loading...';
                submitBtn.disabled = true;
            }
        });
    });
    
    // Initialize column chooser functionality
    const columnToggles = document.querySelectorAll('.column-toggle');
    columnToggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const columnClass = this.dataset.column;
            const isVisible = this.checked;
            toggleColumn(columnClass, isVisible);
        });
    });
    
    // Set initial column visibility based on default settings
    // Hide some columns by default (New Date, Notes, Manufacturer Notes)
    const defaultHiddenColumns = ['col-new-date', 'col-notes', 'col-manufacturer-notes'];
    defaultHiddenColumns.forEach(columnClass => {
        toggleColumn(columnClass, false);
        const toggle = document.querySelector(`[data-column="${columnClass}"]`);
        if (toggle) toggle.checked = false;
    });
});

// Add ripple effect to buttons
function createRipple(event) {
    const button = event.currentTarget;
    const circle = document.createElement('span');
    const diameter = Math.max(button.clientWidth, button.clientHeight);
    const radius = diameter / 2;
    
    circle.style.width = circle.style.height = `${diameter}px`;
    circle.style.left = `${event.clientX - button.offsetLeft - radius}px`;
    circle.style.top = `${event.clientY - button.offsetTop - radius}px`;
    circle.classList.add('ripple');
    
    const ripple = button.getElementsByClassName('ripple')[0];
    if (ripple) {
        ripple.remove();
    }
    
    button.appendChild(circle);
}

document.querySelectorAll('.nav-arrow, button[name="action"]').forEach(btn => {
    btn.addEventListener('click', createRipple);
});
</script>

<style>
/* Ripple effect styles */
.ripple {
    position: absolute;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.4);
    transform: scale(0);
    animation: ripple 0.6s linear;
    pointer-events: none;
}

@keyframes ripple {
    to {
        transform: scale(4);
        opacity: 0;
    }
}
</style>
</body>
</html> 