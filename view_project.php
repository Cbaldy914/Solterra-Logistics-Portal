<?php
session_name("logistics_session");
session_start();

// ---------- AUTH & INPUT --------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$user_id  = $_SESSION['user_id'];
$role     = $_SESSION['role'] ?? 'user';

$project_id           = isset($_GET['project_id'])    ? intval($_GET['project_id'])    : null;
$origin_batch_id      = isset($_GET['origin_batch_id']) ? intval($_GET['origin_batch_id']) : null;
$highlight_delivery_id= isset($_GET['delivery_id'])   ? intval($_GET['delivery_id'])   : null;
if (!$project_id && !$origin_batch_id) die("Project ID or Origin Batch ID is missing.");

// ---------- DB ------------------------------------------------------------
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

$page_title_info  = "Delivery Tracker";
// (Use shared breadcrumbs in template)
$source_vendor_name_for_batch = null;

/* -------------------- context by project_id -----------------------------*/
if ($project_id) {
    if ($role === 'admin' || $role === 'global_admin') {
        $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.* FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $project_id, $user_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) die("You do not have access to this project.");
    $project = $res->fetch_assoc();
    $stmt->close();

    $page_title_info = htmlspecialchars($project['project_name']);
    // For project context, template will render: Dashboard » Project Overview » Delivery Tracker

/* -------------------- context by origin_batch_id ------------------------*/
} elseif ($origin_batch_id) {
    $stmt = $conn->prepare("SELECT vendor_name, account_id FROM modules WHERE id = ?");
    $stmt->bind_param("i", $origin_batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$batch) die("Batch not found.");

    $source_vendor_name_for_batch = $batch['vendor_name'];
    $batch_account_id             = $batch['account_id'];

    if ($role === 'user') {
        $stmt = $conn->prepare("SELECT 1 FROM customer_account_users WHERE user_id=? AND account_id=? LIMIT 1");
        $stmt->bind_param("ii", $user_id, $batch_account_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) die("Forbidden.");
        $stmt->close();
    }

    $page_title_info = "Unassigned Deliveries from Batch: "
                     . htmlspecialchars($source_vendor_name_for_batch)
                     . " (ID: $origin_batch_id)";
    // For batch context, template will render: Dashboard » Modules » Batch Details » Unassigned Deliveries from Batch
}

/* ---------- FILTER LOGIC -------------------------------------------------*/
$baseWhere = [];  $paramTypes = '';  $params = [];
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";

/* context filters */
$selectClause = "SELECT d.*, ss.id as appointment_id,
       (SELECT COUNT(*) FROM project_documents pd 
        WHERE pd.delivery_id = d.id 
        AND pd.document_type = 'pods' 
        AND (pd.document_sub_type = 'Project POD' OR pd.document_sub_type = 'Warehouse POD')
       ) AS has_pod_in_documents
FROM deliveries d";
$joinClause   = " LEFT JOIN site_scheduling ss ON d.id = ss.delivery_id";
if ($project_id) {
    $baseWhere[] = "d.project_id = ?";
    $paramTypes .= "i";
    $params[]    = $project_id;
} else {
    $joinClause  .= " LEFT JOIN projects p ON d.project_id = p.id";
    $baseWhere[]  = "d.supplier = ?";
    $paramTypes  .= "s";
    $params[]     = $source_vendor_name_for_batch;
    $baseWhere[]  = "d.project_id IS NULL";
}

/* NEW: Advanced filters from query params */
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$wattage_filter = $_GET['wattage'] ?? '';
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$search_query = $_GET['search'] ?? '';

// Date range filter
if ($start_date && $end_date) {
    $baseWhere[] = "DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes .= "ss";
    array_push($params, $start_date, $end_date);
} elseif ($start_date) {
    $baseWhere[] = "DATE($filterColumn) >= ?";
    $paramTypes .= "s";
    $params[] = $start_date;
} elseif ($end_date) {
    $baseWhere[] = "DATE($filterColumn) <= ?";
    $paramTypes .= "s";
    $params[] = $end_date;
}

// Wattage filter
if ($wattage_filter !== '') {
    $baseWhere[] = "d.wattage = ?";
    $paramTypes .= "s";
    $params[] = $wattage_filter;
}

// Status filter
if ($status_filter !== '') {
    $baseWhere[] = "d.status_of_delivery = ?";
    $paramTypes .= "s";
    $params[] = $status_filter;
}

// Supplier filter
if ($supplier_filter !== '') {
    $baseWhere[] = "d.supplier = ?";
    $paramTypes .= "s";
    $params[] = $supplier_filter;
}

// Search filter
if ($search_query !== '') {
    $baseWhere[] = "(d.bol_number LIKE ? OR d.supplier LIKE ? OR d.status_of_delivery LIKE ?)";
    $paramTypes .= "sss";
    $search_param = "%$search_query%";
    array_push($params, $search_param, $search_param, $search_param);
}
$whereClause="WHERE 1=1".($baseWhere?" AND ".implode(" AND ",$baseWhere):"");

/* ---------- CSV EXPORT ---------------------------------------------------*/
if(isset($_GET['export']) && $_GET['export']==1){
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition:attachment; filename=deliveries.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Supplier','Wattage','Status of Delivery','Quantity','BOL Number',
                   'Anticipated Delivery Date','Actual Delivery Date',
                   'Associated Pallets','Scheduled','Proof of Delivery']);
    $sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
    $stmt=$conn->prepare($sql);
    if($paramTypes) $stmt->bind_param($paramTypes,...$params);
    $stmt->execute();
    $r=$stmt->get_result();
    while($row=$r->fetch_assoc()){
        fputcsv($out,[
            $row['supplier'],$row['wattage'],$row['status_of_delivery'],$row['quantity'],
            $row['bol_number'],
            $row['anticipated_delivery_date']?date('m-d-Y',strtotime($row['anticipated_delivery_date'])):'',
            $row['actual_delivery_date']?date('m-d-Y',strtotime($row['actual_delivery_date'])):'',
            $row['associated_pallets'],
            $row['scheduled'] ? 'Yes' : 'No',
            $row['proof_of_delivery']?'Yes':'No'
        ]);
    }
    fclose($out); $stmt->close(); $conn->close(); exit();
}

/* ---------- FETCH DELIVERIES --------------------------------------------*/
$sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
$stmt=$conn->prepare($sql);
if($paramTypes) $stmt->bind_param($paramTypes,...$params);
$stmt->execute();
$deliveries=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate stats for each actual status
$total_deliveries = count($deliveries);
$status_counts = [
    'On Water' => 0,
    'Cleared Customs' => 0,
    'In Transit to Warehouse' => 0,
    'Delivered to Warehouse' => 0,
    'In Transit to Project' => 0,
    'Delivered to Project' => 0,
    'Canceled' => 0
];

foreach ($deliveries as $delivery) {
    $status = $delivery['status_of_delivery'];
    if (isset($status_counts[$status])) {
        $status_counts[$status]++;
    }
}

// Filter out zero counts
$active_status_counts = array_filter($status_counts, fn($count) => $count > 0);

// Get unique wattages and suppliers for filters
$unique_wattages = array_unique(array_column($deliveries, 'wattage'));
sort($unique_wattages);
$unique_suppliers = array_unique(array_column($deliveries, 'supplier'));
sort($unique_suppliers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title_info; ?> - Delivery Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }


        .column-hidden{display:none !important;}

        /* Header Section */
        .delivery-tracker-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .delivery-tracker-header::before {
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
            min-width: 100px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }

        /* Different stat item colors based on status */
        .stat-item-total {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.2) 100%);
        }

        .stat-item-pending {
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.15) 0%, rgba(251, 191, 36, 0.2) 100%);
        }

        .stat-item-pending .stat-number {
            color: #d97706;
        }

        .stat-item-transit {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.2) 100%);
        }

        .stat-item-transit .stat-number {
            color: #2563eb;
        }

        .stat-item-delivered {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.2) 100%);
        }

        .stat-item-delivered .stat-number {
            color: #16a34a;
        }

        .stat-item-canceled {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(239, 68, 68, 0.2) 100%);
        }

        .stat-item-canceled .stat-number {
            color: #dc2626;
        }

        .stat-number {
            font-size: 2em;
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

        .btn-clear, .btn-apply, .btn-export, .btn-calendar, .btn-columns {
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

        .btn-export {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-export:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        .btn-calendar {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-calendar:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        .btn-columns {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }

        .btn-columns:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: start;
        }

        .filter-group {
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Make date range span two columns to prevent overlap */
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
        .deliveries-container {
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

        .btn-export-header, .btn-calendar-header, .btn-columns-header {
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

        .btn-calendar-header {
            background: rgba(255, 255, 255, 0.95);
            color: #7c3aed;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-calendar-header:hover {
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

        .table-responsive {
            width: 100%;
            overflow-x: auto;
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
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
            border: none;
            background: white;
        }

        table td {
            padding: 16px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.08);
            vertical-align: middle;
            border: none;
        }

        table tbody tr {
            transition: background 0.2s ease, box-shadow 0.2s ease;
        }

        /* Avoid horizontal translate that can trigger scrollbar flicker */
        table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            box-shadow: inset 4px 0 0 rgba(72, 140, 154, 0.25);
        }

        /* Action Buttons - NEW UNIFIED STYLE */
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.85em;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.25);
        }

        .action-btn-primary:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.35);
            color: white;
        }

        .action-btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.25);
        }

        .action-btn-success:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.35);
            color: white;
        }

        .action-btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.25);
        }

        .action-btn-warning:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
            color: white;
        }

        .action-btn-outline {
            background: white;
            color: #488C9A;
            border: 2px solid #488C9A;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.15);
        }

        .action-btn-outline:hover {
            background: #488C9A;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25);
        }

        .action-btn i {
            font-size: 0.9em;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 500;
        }

        .status-pending {
            background: rgba(251, 191, 36, 0.15);
            color: #d97706;
        }

        .status-transit {
            background: rgba(59, 130, 246, 0.15);
            color: #2563eb;
        }

        .status-delivered {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .status-canceled {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
        }

        /* Highlighted Delivery */
        .highlighted-delivery {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important;
            border-left: 4px solid #488C9A !important;
            animation: highlightPulse 2s ease-in-out;
        }

        @keyframes highlightPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 rgba(72, 140, 154, 0);
            }
            50% {
                transform: scale(1.01);
                box-shadow: 0 0 20px rgba(72, 140, 154, 0.3);
            }
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
            color: white;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 24px;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .modal-close:hover {
            transform: scale(1.1);
        }

        .modal-body {
            padding: 0;
            max-height: 400px;
            overflow-y: auto;
            position: relative;
        }

        .pallet-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .pallet-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8f9fa;
        }

        .pallet-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            border-bottom: 2px solid rgba(72, 140, 154, 0.2);
        }

        .pallet-table td {
            padding: 12px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.1);
        }

        .pallet-table tr:hover {
            background: rgba(72, 140, 154, 0.05);
        }

        /* Column Chooser Dropdown */
        .column-chooser-dropdown {
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

        .column-option {
            display: flex;
            align-items: center;
            padding: 10px 16px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            color: #293E4C;
            font-size: 0.9em;
        }

        .column-option:hover {
            background-color: rgba(72, 140, 154, 0.08);
        }

        .column-option input[type=checkbox] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        .column-chooser-footer {
            padding: 12px 16px;
            border-top: 1px solid rgba(72, 140, 154, 0.2);
            background: #f8f9fa;
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

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .header-stats {
                justify-content: space-between;
            }

            .stat-item {
                flex: 1;
                min-width: 80px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            /* Reset date range span on mobile */
            .filter-group:has(.date-range-group) {
                grid-column: span 1;
            }

            .date-range-group {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-actions button {
                width: 100%;
                justify-content: center;
            }

            .table-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .table-header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .btn-export-header, .btn-calendar-header, .btn-columns-header {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .delivery-tracker-header {
                padding: 20px;
            }

            .header-info h1 {
                font-size: 1.8em;
            }

            .stat-number {
                font-size: 1.5em;
            }

            .filter-section {
                padding: 20px;
            }
        }

        .time-filter-header{display:flex;justify-content:space-between;align-items:center;margin-top:30px;margin-bottom:10px;flex-wrap:wrap;}
        .time-filters{display:flex;gap:10px;}
        .time-filters a{text-decoration:none;padding:6px 12px;background:#eee;border-radius:4px;color:#333;}
        .time-filters a.active{background:#488C9A;color:#fff;}
        .date-navigation{display:flex;align-items:center;gap:10px;margin:20px;}
        .nav-arrow{font-weight:bold;cursor:pointer;background:#eee;border:none;padding:5px 10px;border-radius:4px;}
        .nav-arrow:hover{background:#ccc;}
        .date-label{font-weight:bold;font-size:1.1em;}
        .right-filters{display:flex;flex-direction:column;gap:10px;align-items:flex-start;}
        .back-icon{display:inline-flex;align-items:center;text-decoration:none;margin:10px;color:#333;}
        .back-icon svg{width:24px;height:24px;margin-right:5px;}
        .breadcrumb{display:flex;margin-bottom:20px;margin-top:10px;margin-left:20px;}
        .breadcrumb a{color:#488C9A;text-decoration:none;}
        .breadcrumb .separator{margin:0 8px;color:#6c757d;}
        table{width:100%;border-collapse:collapse;margin-bottom:20px;}
        table,th,td{border:1px solid #ccc;}
        th,td{padding:10px;}
        tr:hover{background:#f1f1f1;}
        .table-responsive{width:100%;overflow-x:auto;}
        @media screen and (max-width:768px){.mobile-hide{display:none !important;}}
        .highlighted-delivery{background:linear-gradient(135deg,#fff3cd 0%,#ffeaa7 100%) !important;border:3px solid #488C9A !important;box-shadow:0 0 15px rgba(72,140,154,0.3) !important;animation:highlightPulse 2s ease-in-out;}
        @keyframes highlightPulse{0%,100%{transform:scale(1);box-shadow:0 0 15px rgba(72,140,154,0.3);}50%{transform:scale(1.02);box-shadow:0 0 25px rgba(72,140,154,0.5);}}
        /* Filters dropdown */
        .filters-dropdown-container{position:relative;display:inline-block;}
        .filters-dropdown-btn{background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border:none;padding:12px 20px;border-radius:10px;cursor:pointer;font-size:.95em;font-weight:600;transition:all .3s cubic-bezier(.25,.46,.45,.94);display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(72,140,154,.3);}
        .filters-dropdown-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(72,140,154,.4);}
        .filters-dropdown-content{position:absolute;top:100%;right:0;background:white;border:1px solid #e1e5e9;border-radius:12px;box-shadow:0 12px 24px rgba(0,0,0,.15);z-index:1000;min-width:400px;max-width:500px;max-height:500px;overflow-y:auto;backdrop-filter:blur(10px);}
        .filters-dropdown-header{padding:16px 20px;background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border-bottom:none;font-weight:700;font-size:1.1em;text-align:center;border-radius:12px 12px 0 0;}
        .filter-item{padding:16px 20px;border-bottom:1px solid #f1f3f4;}
        .filter-item:last-child{border-bottom:none;border-radius:0 0 12px 12px;}
        .filter-item label{display:block;font-weight:600;color:#293E4C;margin-bottom:8px;font-size:.95em;}
        .filter-item input,.filter-item select{width:95%;padding:10px 12px;border:2px solid #e1e5e9;border-radius:8px;font-size:14px;transition:border-color .2s ease,box-shadow .2s ease;background-color:white;}
        .filter-item input:focus,.filter-item select:focus{outline:none;border-color:#488C9A;box-shadow:0 0 0 3px rgba(72,140,154,.1);}
        /* Column chooser */
        .column-chooser-container{position:relative;display:inline-block;}
        .column-chooser-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .column-chooser-btn:hover{background-color:#3A6E7F;}
        .calendar-view-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .calendar-view-btn:hover{background-color:#3A6E7F;}
        .column-chooser-dropdown{position:absolute;top:100%;right:0;background:white;border:1px solid #ddd;border-radius:4px;box-shadow:0 4px 8px rgba(0,0,0,.1);z-index:1000;min-width:250px;max-width:300px;max-height:400px;overflow-y:auto;}
        .column-chooser-header{padding:12px 16px;background-color:#f8f9fa;border-bottom:1px solid #ddd;font-weight:600;color:#293E4C;}
        .column-chooser-options{padding:8px 0;max-height:300px;overflow-y:auto;}
        .column-option{display:flex;align-items:center;padding:6px 16px;cursor:pointer;transition:background-color .2s ease;}
        .column-option:hover{background-color:#f8f9fa;}
        .column-option input[type=checkbox]{margin-right:8px;cursor:pointer;}
        .column-chooser-footer{padding:8px 16px;border-top:1px solid #ddd;background-color:#f8f9fa;}
        .reset-columns-btn{background-color:#6c757d;color:white;border:none;padding:4px 12px;border-radius:3px;cursor:pointer;font-size:.8em;transition:background-color .3s ease;}
        .reset-columns-btn:hover{background-color:#5a6268;}
        .column-hidden{display:none !important;}
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if ($project_id) {
            $extra[] = ['label' => 'Project Overview', 'url' => 'project_overview.php?project_id='.(int)$project_id];
            echo slp_render_breadcrumbs(['current_label' => 'Delivery Tracker', 'extra' => $extra]);
        } else {
            $extra[] = ['label' => 'Modules', 'url' => 'modules.php'];
            $extra[] = ['label' => 'Batch Details', 'url' => 'module_overview.php?batch_id='.(int)$origin_batch_id];
            echo slp_render_breadcrumbs(['current_label' => 'Unassigned Deliveries from Batch', 'extra' => $extra]);
        }
    ?>

    <!-- Header Section -->
    <div class="delivery-tracker-header">
        <div class="header-content">
            <div class="header-info">
        <h1><?php echo $page_title_info; ?></h1>
                <p class="header-subtitle">Track and manage all deliveries for this project</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-total">
                    <p class="stat-number"><?php echo $total_deliveries; ?></p>
                    <p class="stat-label">Total Deliveries</p>
            </div>
                <?php foreach ($active_status_counts as $status => $count): ?>
                    <?php
                    // Determine status badge color class
                    $status_badge_class = 'stat-item-default';
                    if ($status === 'On Water') {
                        $status_badge_class = 'stat-item-transit';
                    } elseif ($status === 'Cleared Customs') {
                        $status_badge_class = 'stat-item-pending';
                    } elseif (strpos($status, 'In Transit') !== false) {
                        $status_badge_class = 'stat-item-transit';
                    } elseif (strpos($status, 'Delivered') !== false) {
                        $status_badge_class = 'stat-item-delivered';
                    } elseif ($status === 'Canceled') {
                        $status_badge_class = 'stat-item-canceled';
                    }
                    ?>
                    <div class="stat-item <?php echo $status_badge_class; ?>">
                        <p class="stat-number"><?php echo $count; ?></p>
                        <p class="stat-label"><?php echo htmlspecialchars($status); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
                            </div>

    <!-- Filter Section -->
    <div class="filter-section">
                            <form id="filterForm" method="get">
            <?php if ($project_id): ?>
                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <?php elseif ($origin_batch_id): ?>
                                    <input type="hidden" name="origin_batch_id" value="<?php echo $origin_batch_id; ?>">
                                <?php endif; ?>
            
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
                    <label class="filter-label" for="wattageFilter">Wattage</label>
                    <select name="wattage" id="wattageFilter" class="filter-select">
                        <option value="">All Wattages</option>
                        <?php foreach ($unique_wattages as $wattage): ?>
                            <option value="<?php echo htmlspecialchars($wattage); ?>" <?php echo $wattage_filter == $wattage ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wattage); ?>W
                            </option>
                        <?php endforeach; ?>
                                    </select>
                                </div>

                <div class="filter-group">
                    <label class="filter-label" for="statusFilter">Status</label>
                    <select name="status" id="statusFilter" class="filter-select">
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

                <?php if ($project_id): ?>
                <div class="filter-group">
                    <label class="filter-label" for="supplierFilter">Supplier</label>
                    <select name="supplier" id="supplierFilter" class="filter-select">
                        <option value="">All Suppliers</option>
                        <?php foreach ($unique_suppliers as $supplier): ?>
                            <option value="<?php echo htmlspecialchars($supplier); ?>" <?php echo $supplier_filter == $supplier ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                        </div>
                <?php endif; ?>

            <div class="filter-group">
                <label class="filter-label" for="searchFilter">Search</label>
                <input type="text" name="search" id="searchFilter" class="filter-input" placeholder="Search BOL, supplier, status..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
        </div>
    </form>
</div>

    <!-- Deliveries Table -->
    <div class="deliveries-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-truck"></i>
                Deliveries
            </h3>
            <div class="table-header-actions">
                <button type="submit" form="filterForm" name="export" value="1" class="btn-export-header">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <?php if ($project_id): ?>
                <button type="button" class="btn-calendar-header" onclick="window.location.href='scheduling.php?project_id=<?php echo $project_id; ?>'">
                    <i class="fas fa-calendar"></i>
                    Calendar View
                </button>
                <?php endif; ?>
                <div style="position: relative;">
                    <button type="button" class="btn-columns-header" onclick="toggleColumnChooser()">
                        <i class="fas fa-columns"></i>
                        Columns
                    </button>
                    <div id="columnChooserDropdown" class="column-chooser-dropdown" style="display:none;">
                        <div class="column-chooser-header">Select Columns to Show:</div>
                        <div class="column-chooser-options">
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="supplier-column" checked>
                                Supplier
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="wattage-column" checked>
                                Wattage
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="status-column" checked>
                                Status
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="quantity-column" checked>
                                Quantity
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="bol-column" checked>
                                BOL Number
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="anticipated-column" checked>
                                Anticipated Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="actual-column" checked>
                                Actual Date
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pallets-column" checked>
                                Pallets
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="scheduled-column" checked>
                                Scheduled
                            </label>
                            <label class="column-option">
                                <input type="checkbox" class="column-toggle" data-column="pod-column" checked>
                                Proof of Delivery
                            </label>
                        </div>
                        <div class="column-chooser-footer">
                            <button type="button" onclick="resetColumns()" class="btn-clear" style="width: 100%;">
                                Reset to Default
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <?php if ($deliveries): ?>
            <table id="deliveriesTable">
                <thead>
                    <tr>
                        <th class="supplier-column">Supplier</th>
                        <th class="wattage-column">Wattage</th>
                        <th class="status-column">Status</th>
                        <th class="quantity-column">Quantity</th>
                        <th class="bol-column">BOL Number</th>
                        <th class="anticipated-column">Anticipated Date</th>
                        <th class="actual-column">Actual Date</th>
                        <th class="pallets-column">Pallets</th>
                        <th class="scheduled-column">Scheduled</th>
                        <th class="pod-column">Proof of Delivery</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $palletConn=getDBConnection();
                    $stmtPallets=$palletConn->prepare("
                        SELECT ip.id,ip.pallet_identifier,ip.wattage,ip.quantity
                        FROM delivery_pallets dp
                        JOIN inventory_pallets ip ON dp.inventory_pallet_id=ip.id
                        WHERE dp.delivery_id = ?
                        ORDER BY ip.id");
                    ?>
                <?php foreach ($deliveries as $delivery): ?>
                        <?php
                    $stmtPallets->bind_param("i", $delivery['id']);
                        $stmtPallets->execute();
                    $palletRows = $stmtPallets->get_result()->fetch_all(MYSQLI_ASSOC);
                    $count = count($palletRows);
                    $palletData = json_encode($palletRows, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
                    
                    // Determine status class
                    $status_class = 'status-badge ';
                    if ($delivery['status_of_delivery'] === 'On Water') {
                        $status_class .= 'status-transit';
                    } elseif ($delivery['status_of_delivery'] === 'Cleared Customs') {
                        $status_class .= 'status-pending';
                    } elseif (strpos($delivery['status_of_delivery'], 'In Transit') !== false) {
                        $status_class .= 'status-transit';
                    } elseif (strpos($delivery['status_of_delivery'], 'Delivered') !== false) {
                        $status_class .= 'status-delivered';
                    } elseif ($delivery['status_of_delivery'] === 'Canceled') {
                        $status_class .= 'status-canceled';
                    }
                    ?>
                    <tr <?php if ($highlight_delivery_id == $delivery['id']) echo 'class="highlighted-delivery" id="highlighted-delivery"'; ?> data-delivery-id="<?php echo $delivery['id']; ?>">
                            <td class="supplier-column"><?php echo htmlspecialchars($delivery['supplier']); ?></td>
                        <td class="wattage-column"><?php echo htmlspecialchars($delivery['wattage']); ?>W</td>
                        <td class="status-column">
                            <span class="<?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($delivery['status_of_delivery']); ?>
                            </span>
                        </td>
                            <td class="quantity-column"><?php echo htmlspecialchars($delivery['quantity']); ?></td>
                            <td class="bol-column"><?php echo htmlspecialchars($delivery['bol_number']); ?></td>
                        <td class="anticipated-column"><?php echo $delivery['anticipated_delivery_date'] ? date('m/d/Y', strtotime($delivery['anticipated_delivery_date'])) : '—'; ?></td>
                        <td class="actual-column"><?php echo $delivery['actual_delivery_date'] ? date('m/d/Y', strtotime($delivery['actual_delivery_date'])) : '—'; ?></td>
                            <td class="pallets-column">
                            <?php if ($count): ?>
                                <button type="button" class="action-btn action-btn-primary view-pallets-btn"
                                        data-pallets='<?php echo htmlspecialchars($palletData, ENT_QUOTES); ?>'>
                                    <i class="fas fa-boxes"></i>
                                        View Pallets (<?php echo $count; ?>)
                                    </button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                            </td>
                            <td class="scheduled-column">
                                <?php if ($delivery['scheduled'] == 1): ?>
                                    <?php if (!empty($delivery['project_id']) && !empty($delivery['appointment_id'])): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>&appointment_id=<?php echo $delivery['appointment_id']; ?>&auto_edit=1" 
                                       class="action-btn action-btn-success">
                                        <i class="fas fa-calendar-check"></i>
                                        View Appointment
                                    </a>
                                    <?php else: ?>
                                    <span class="status-badge status-delivered">
                                        <i class="fas fa-check-circle"></i>
                                        Scheduled
                                    </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!empty($delivery['project_id']) && $delivery['status_of_delivery'] === 'In Transit to Project'): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>" 
                                       class="action-btn action-btn-warning">
                                        <i class="fas fa-calendar-plus"></i>
                                        Schedule
                                    </a>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="pod-column">
                                <?php if (!empty($delivery['proof_of_delivery']) || !empty($delivery['has_pod_in_documents'])): ?>
                                <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank" class="action-btn action-btn-primary">
                                    <i class="fas fa-file-pdf"></i>
                                    View POD
                                </a>
                                <?php else: ?>
                                    <?php if (in_array($_SESSION['role'], ['global_admin', 'admin'])): ?>
                                    <a href="upload_pod?delivery_id=<?php echo $delivery['id']; ?>" class="action-btn action-btn-outline">
                                        <i class="fas fa-upload"></i>
                                        Upload POD
                                    </a>
                                    <?php else: ?>
                                    —
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php $stmtPallets->close(); $palletConn->close(); ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Deliveries Found</h3>
                <p>No deliveries match your current filter criteria. Try adjusting your filters.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pallets Modal -->
    <div id="associatedPalletsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
            <h2>Associated Pallets</h2>
                <span class="modal-close" id="closePalletModalBtn">&times;</span>
            </div>
            <div class="modal-body">
                <div id="palletList"></div>
            </div>
        </div>
    </div>
</main>

<script>
// Global variables
var associatedPalletsModal, palletListDiv;

// Clear filters
function clearFilters() {
    document.getElementById('filterForm').reset();
    const url = new URL(window.location.href);
    <?php if ($project_id): ?>
    url.search = '?project_id=<?php echo $project_id; ?>';
    <?php elseif ($origin_batch_id): ?>
    url.search = '?origin_batch_id=<?php echo $origin_batch_id; ?>';
    <?php endif; ?>
    window.location.href = url.toString();
}

// Column chooser
function toggleColumnChooser() {
    const dropdown = document.getElementById('columnChooserDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}
function toggleColumn(col, show) {
    document.querySelectorAll('.' + col).forEach(el => {
        show ? el.classList.remove('column-hidden') : el.classList.add('column-hidden');
    });
}

function resetColumns() {
    document.querySelectorAll('.column-toggle').forEach(cb => {
        cb.checked = true;
        toggleColumn(cb.dataset.column, true);
    });
    saveColumnPreferences();
}

function saveColumnPreferences() {
    var prefs = {};
    document.querySelectorAll('.column-toggle').forEach(cb => {
        prefs[cb.dataset.column] = cb.checked;
    });
    localStorage.setItem('viewProjectColumnPreferences', JSON.stringify(prefs));
}

function loadColumnPreferences() {
    var p = localStorage.getItem('viewProjectColumnPreferences');
    if (!p) return;
    p = JSON.parse(p);
    document.querySelectorAll('.column-toggle').forEach(cb => {
        if (p.hasOwnProperty(cb.dataset.column)) {
            cb.checked = p[cb.dataset.column];
            toggleColumn(cb.dataset.column, cb.checked);
        }
    });
}

// Pallet modal
function showPalletModal(btn) {
    if (!associatedPalletsModal) {
        associatedPalletsModal = document.getElementById('associatedPalletsModal');
        palletListDiv = document.getElementById('palletList');
    }
    palletListDiv.innerHTML = '';
    const pallets = JSON.parse(btn.dataset.pallets || '[]');
    
    if (!pallets.length) {
        palletListDiv.innerHTML = '<p style="text-align: center; color: #6c757d;">No pallets found.</p>';
    } else {
        const tbl = document.createElement('table');
        tbl.className = 'pallet-table';
        
        const head = tbl.createTHead().insertRow();
        ['Identifier', 'Wattage', 'Quantity', 'Actions'].forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            head.appendChild(th);
        });
        
        const body = tbl.createTBody();
        pallets.forEach(p => {
            const r = body.insertRow();
            const id = r.insertCell();
            id.textContent = p.pallet_identifier || `ID: ${p.id}`;
            
            const wat = r.insertCell();
            wat.textContent = p.wattage ? `${p.wattage}W` : '—';
            
            const qty = r.insertCell();
            qty.textContent = p.quantity || '—';
            
            const act = r.insertCell();
            const a = document.createElement('a');
            a.href = `pallet_details.php?pallet_id=${p.id}`;
            a.className = 'action-btn action-btn-primary';
            a.innerHTML = '<i class="fas fa-eye"></i> View Details';
            act.appendChild(a);
        });
        
        palletListDiv.appendChild(tbl);
    }
    
    associatedPalletsModal.style.display = 'block';
}

function closeAssociatedPalletModal() {
    if (associatedPalletsModal) {
        associatedPalletsModal.style.display = 'none';
        palletListDiv.innerHTML = '';
    }
}

// DOM ready
document.addEventListener('DOMContentLoaded', () => {
    loadColumnPreferences();
    
    // View pallets buttons
    document.querySelectorAll('.view-pallets-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            showPalletModal(this);
        });
    });
    
    // Close modal
    document.getElementById('closePalletModalBtn')?.addEventListener('click', closeAssociatedPalletModal);
    
    // Column toggles
    document.querySelectorAll('.column-toggle').forEach(cb => {
        cb.addEventListener('change', () => {
            toggleColumn(cb.dataset.column, cb.checked);
            saveColumnPreferences();
        });
    });
    
    // Close dropdowns on outside click
    document.addEventListener('click', e => {
        const columnChooser = document.getElementById('columnChooserDropdown');
        if (columnChooser && !e.target.closest('.btn-columns-header') && !columnChooser.contains(e.target)) {
            columnChooser.style.display = 'none';
        }
    });
    
    // Close modal on outside click
    window.addEventListener('click', e => {
        if (e.target === associatedPalletsModal) {
            closeAssociatedPalletModal();
        }
    });
    
    // Highlight delivery if specified
    const hi = document.getElementById('highlighted-delivery');
    if (hi) {
        setTimeout(() => {
            hi.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 500);
    }
});
</script>
</body>
</html>
