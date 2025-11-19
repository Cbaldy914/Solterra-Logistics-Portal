<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/warranty_filters.php';

$conn = getDBConnection();

// Accessible projects for filter
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$allowedProjectIds = getAllowedProjectIds($conn, $userId, $role);

$projects = [];
if ($allowedProjectIds === null) {
    $res = $conn->query('SELECT id, project_name FROM projects ORDER BY project_name ASC');
    while ($row = $res->fetch_assoc()) $projects[] = $row;
} elseif (!empty($allowedProjectIds)) {
    $place = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $types = str_repeat('i', count($allowedProjectIds));
    $stmt = $conn->prepare('SELECT id, project_name FROM projects WHERE id IN (' . $place . ') ORDER BY project_name ASC');
    $stmt->bind_param($types, ...$allowedProjectIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $projects[] = $row;
    $stmt->close();
}

// Filters persistence
$filters = loadPersistedWarrantyFilters();
$incoming = getWarrantyFiltersFromRequest();
foreach ($incoming as $k => $v) {
    if ($k === 'issue_types' || $k === 'statuses') {
        $filters[$k] = (array)$v;
    } else {
        $filters[$k] = $v;
    }
}
persistWarrantyFilters($filters);

// Re-compute header stats using current filters (project scope + filters)
// Build WHERE
$where = [];
$types = '';
$params = [];

// Project scope (specific or allowed list)
if (!empty($filters['project_id'])) {
    $where[] = 'ss.project_id = ?';
    $types  .= 'i';
    $params[] = (int)$filters['project_id'];
} elseif (is_array($allowedProjectIds)) {
    if (!empty($allowedProjectIds)) {
        $place = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $where[] = 'ss.project_id IN (' . $place . ')';
        $types  .= str_repeat('i', count($allowedProjectIds));
        foreach ($allowedProjectIds as $pid) { $params[] = (int)$pid; }
    } else {
        // No accessible projects => zero stats
        $stats = [
            'total_tickets' => 0,
            'closed_tickets' => 0,
            'pending_tickets' => 0,
            'approved_tickets' => 0,
        ];
    }
}

// Apply filters
if (!isset($stats)) {
    if ((int)($filters['hide_closed'] ?? 0) === 1) {
        $where[] = "w.status <> 'Closed'";
    }
    if (!empty($filters['issue_types'])) {
        $place = implode(',', array_fill(0, count($filters['issue_types']), '?'));
        $where[] = 'w.issue_type IN (' . $place . ')';
        $types  .= str_repeat('s', count($filters['issue_types']));
        foreach ($filters['issue_types'] as $it) { $params[] = (string)$it; }
    }
    if (!empty($filters['responsible_party'])) {
        $where[] = 'w.responsible_party = ?';
        $types  .= 's';
        $params[] = (string)$filters['responsible_party'];
    }
    if (!empty($filters['statuses'])) {
        $place = implode(',', array_fill(0, count($filters['statuses']), '?'));
        $where[] = 'w.status IN (' . $place . ')';
        $types  .= str_repeat('s', count($filters['statuses']));
        foreach ($filters['statuses'] as $st) { $params[] = (string)$st; }
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'DATE(w.created_at) >= ?';
        $types  .= 's';
        $params[] = (string)$filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'DATE(w.created_at) <= ?';
        $types  .= 's';
        $params[] = (string)$filters['date_to'];
    }

    $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
    $sql = "SELECT 
                COUNT(*) as total_tickets,
                SUM(CASE WHEN w.status = 'Closed' THEN 1 ELSE 0 END) as closed_tickets,
                SUM(CASE WHEN w.status LIKE '%Pending%' THEN 1 ELSE 0 END) as pending_tickets,
                SUM(CASE WHEN w.status LIKE '%Approved%' THEN 1 ELSE 0 END) as approved_tickets
            FROM warranty_claims w
            JOIN site_scheduling ss ON ss.id = w.scheduling_id
            $whereSql";
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $stats = $res->fetch_assoc() ?: [
        'total_tickets' => 0,
        'closed_tickets' => 0,
        'pending_tickets' => 0,
        'approved_tickets' => 0,
    ];
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exceptions Report</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }

        /* Header Section - EXACT MATCH to sustainability */
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

        .stat-item-closed {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(34, 197, 94, 0.2) 100%);
        }

        .stat-item-closed .stat-number {
            color: #16a34a;
        }

        .stat-item-pending {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(245, 158, 11, 0.2) 100%);
        }

        .stat-item-pending .stat-number {
            color: #d97706;
        }

        .stat-item-approved {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(59, 130, 246, 0.2) 100%);
        }

        .stat-item-approved .stat-number {
            color: #2563eb;
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

        /* Filter Section - EXACT MATCH to sustainability */
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

        /* Custom dropdown styling for issue/status */
        .issue-dropdown, .status-dropdown {
            position: relative;
        }

        .issue-select, .status-select {
            padding: 12px 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
        }

        .issue-select:hover, .status-select:hover {
            border-color: #488C9A;
        }

        .issue-panel, .status-panel {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            z-index: 1050;
            background: white;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 12px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            padding: 12px;
            min-width: 280px;
            width: max(280px, 100%);
            display: none;
        }

        .issue-panel.open, .status-panel.open {
            display: block;
        }

        .issue-item, .status-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            cursor: pointer;
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .issue-item:hover, .status-item:hover {
            background-color: rgba(72, 140, 154, 0.08);
        }

        .issue-item input[type="checkbox"],
        .status-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
            cursor: pointer;
        }

        .issue-item span, .status-item span {
            font-size: 0.95em;
            color: #293E4C;
        }

        .issue-item input:checked + span,
        .status-item input:checked + span {
            font-weight: 700;
            color: #1f3945;
        }

        /* Table Container - EXACT MATCH to sustainability */
        .table-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            margin: 0 20px;
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

        .btn-export-header, .btn-columns-header, .btn-new-ticket-header {
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

        .btn-new-ticket-header {
            background: rgba(255, 255, 255, 0.95);
            color: #2563eb;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-new-ticket-header:hover {
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        /* Column Chooser - EXACT MATCH to sustainability */
        .column-chooser-wrapper {
            position: relative;
        }

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

        .column-chooser-content.show {
            display: block;
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
            margin: 0;
        }

        .column-item input[type=checkbox] {
            margin-right: 10px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        /* Table Styling - EXACT MATCH to sustainability */
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
            cursor: pointer;
        }

        table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            transform: translateX(4px);
        }

        /* Column visibility */
        .column-hidden {
            display: none !important;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid rgba(0, 0, 0, 0.05);
            white-space: nowrap;
        }

        .status-Closed {
            background: #e9ecef;
            color: #6c757d;
        }

        .status-Submitted {
            background: #d1e7ff;
            color: #084298;
        }

        .status-In\ Review {
            background: #fff3cd;
            color: #664d03;
        }

        .status-Pending\ Manufacturer,
        .status-Pending\ EPC,
        .status-Pending\ Carrier {
            background: #fde2e1;
            color: #842029;
        }

        .status-Approved\ -\ Credit {
            background: #d4edda;
            color: #0f5132;
        }

        .status-Approved\ -\ Replacement {
            background: #e2e3ff;
            color: #3d3dff;
        }

        .status-Replacement\ Shipped {
            background: #cff4fc;
            color: #055160;
        }

        .status-Rejected {
            background: #f8d7da;
            color: #842029;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 24px;
            background: linear-gradient(135deg, #E8F4F6, #F5FAFB);
            color: #1f3945;
            font-weight: 600;
            border: 1px solid #e1eef1;
            font-size: 0.85em;
        }

        /* DataTables customization to match theme */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            padding: 16px 24px 8px 24px;
        }

        .dataTables_wrapper .dataTables_length {
            float: left;
        }

        .dataTables_wrapper .dataTables_filter {
            float: right;
        }

        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: #293E4C;
            font-size: 0.9em;
        }

        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            padding: 8px 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
        }

        .dataTables_wrapper .dataTables_length select:focus,
        .dataTables_wrapper .dataTables_filter input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 16px 24px;
        }

        .dataTables_wrapper .dataTables_info {
            float: left;
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }

        .dataTables_wrapper .dataTables_paginate {
            float: right;
            flex-shrink: 0;
        }

        .dataTables_wrapper .dataTables_top .dataTables_paginate {
            margin-left: auto;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 8px !important;
            background: white !important;
            border: 2px solid rgba(72, 140, 154, 0.15) !important;
            margin: 0 3px !important;
            padding: 8px 16px !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            font-size: 0.9em !important;
            color: #293E4C !important;
            transition: all 0.3s ease !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #488C9A, #3A6E7F) !important;
            color: white !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.disabled) {
            background: rgba(72, 140, 154, 0.1) !important;
            border: 2px solid #488C9A !important;
            color: #488C9A !important;
            transform: translateY(-1px);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            color: #9ca3af !important;
            cursor: not-allowed !important;
            background: #f3f4f6 !important;
            border-color: #e5e7eb !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            transform: none !important;
            background: #f3f4f6 !important;
        }

        /* Pagination wrapper - no longer used (replaced by .pagination-container) */
        #pagination-wrapper { display: none; }

        /* DataTables layout sections */
        .dataTables_wrapper .dataTables_top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            padding: 0;
            background: transparent;
            border: none;
            gap: 16px;
        }

        .dataTables_wrapper .dataTables_bottom {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            padding: 0;
            background: white;
            border-top: 1px solid rgba(72, 140, 154, 0.08);
        }

        /* Clear floats */
        .dataTables_wrapper:after {
            content: "";
            display: table;
            clear: both;
        }

        /* Breadcrumbs */
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

        /* Resolution badges */
        .resolution-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
            white-space: nowrap;
        }

        .resolution-badge.credit {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }

        /* Action button in table */
        .icon-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, #488C9A, #3A6E7F);
            color: #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 8px rgba(72, 140, 154, 0.25);
        }

        .icon-btn:hover {
            transform: translateY(-1px) scale(1.05);
            box-shadow: 0 6px 12px rgba(72, 140, 154, 0.35);
        }

        /* Switch styling */
        .form-check-input[type="checkbox"] {
            width: 2.5em;
            height: 1.25em;
            cursor: pointer;
            accent-color: #488C9A;
        }

        .form-check-label {
            margin-left: 8px;
            cursor: pointer;
            color: #293E4C;
        }

        /* Pagination styles - match view_project.php */
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

        .pagination-info { font-size: 0.9em; color: #6c757d; font-weight: 500; }

        .pagination-controls { display: flex; align-items: center; gap: 12px; }

        .pagination-controls label { font-size: 0.9em; margin-right: 5px; color: #293E4C; font-weight: 500; }

        .pagination-controls input,
        .pagination-controls select {
            padding: 8px 12px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
        }

        .pagination-controls input:focus { outline: none; border-color: #488C9A; box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2); }

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

        .pagination-controls button:hover:not(:disabled) { background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%); transform: translateY(-1px); }

        .pagination-controls button:disabled { background: #e9ecef; color: #6c757d; cursor: not-allowed; transform: none; }

        #pageInfo { color: #293E4C; font-weight: 600; padding: 0 8px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php 
        $selectedProjectId = (int)($filters['project_id'] ?? 0);
        $selectedProjectName = '';
        if ($selectedProjectId > 0) {
            foreach ($projects as $p) {
                if ((int)$p['id'] === $selectedProjectId) {
                    $selectedProjectName = (string)$p['project_name'];
                    break;
                }
            }
        }
    ?>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Exceptions Report',
            'project_id' => (int)$selectedProjectId
        ]);
    ?>

    <!-- Header Section - EXACT MATCH to sustainability -->
    <div class="sustainability-tracker-header">
        <div class="header-content">
            <div class="header-info">
                <h1>Exceptions Report</h1>
                <p class="header-subtitle">Warranty claims and issue tracking for module deliveries</p>
            </div>
            <div class="header-stats">
                <div class="stat-item stat-item-total">
                    <p class="stat-number" id="stat-total"><?php echo number_format($stats['total_tickets'] ?? 0); ?></p>
                    <p class="stat-label">Total Tickets</p>
                </div>
                <div class="stat-item stat-item-closed">
                    <p class="stat-number" id="stat-closed"><?php echo number_format($stats['closed_tickets'] ?? 0); ?></p>
                    <p class="stat-label">Closed</p>
                </div>
                <div class="stat-item stat-item-pending">
                    <p class="stat-number" id="stat-pending"><?php echo number_format($stats['pending_tickets'] ?? 0); ?></p>
                    <p class="stat-label">Pending</p>
                </div>
                <div class="stat-item stat-item-approved">
                    <p class="stat-number" id="stat-approved"><?php echo number_format($stats['approved_tickets'] ?? 0); ?></p>
                    <p class="stat-label">Approved</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section - EXACT MATCH to sustainability -->
    <div class="filter-section">
        <form id="filtersForm">
            <div class="filter-header">
                <h2 class="filter-title">
                    <i class="fas fa-filter"></i>
                    Filter Warranty Claims
                </h2>
                <div class="filter-actions">
                    <button type="button" class="btn-clear" onclick="clearFilters()">
                        <i class="fas fa-times"></i>
                        Clear
                    </button>
                    <button type="button" id="applyFilters" class="btn-apply">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                </div>
            </div>

            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label" for="f_project">Project</label>
                    <select id="f_project" name="project_id" class="filter-select">
                        <option value="0">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$filters['project_id']===(int)$p['id'])?'selected':''; ?>>
                                <?php echo htmlspecialchars($p['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group issue-dropdown">
                    <label class="filter-label">Issue Type</label>
                    <div class="issue-select" id="issueSelect">
                        <span class="label" id="issueLabel">All types</span>
                        <span>▾</span>
                    </div>
                    <div class="issue-panel" id="issuePanel">
                        <?php 
                        $issueTypes = [
                            'damaged' => 'Damaged',
                            'quantity_discrepancy' => 'Quantity Discrepancy',
                            'both' => 'Both'
                        ];
                        foreach ($issueTypes as $val => $label):
                            $checked = in_array($val, (array)$filters['issue_types'], true) ? 'checked' : '';
                        ?>
                        <label class="issue-item">
                            <input type="checkbox" class="form-check-input" value="<?php echo $val; ?>" <?php echo $checked; ?>>
                            <span><?php echo $label; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="issue_types[]" id="issue_hidden_1" value="<?php echo htmlspecialchars((string)(($filters['issue_types'][0] ?? ''))); ?>">
                    <input type="hidden" name="issue_types[]" id="issue_hidden_2" value="<?php echo htmlspecialchars((string)(($filters['issue_types'][1] ?? ''))); ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="f_resp">Responsible Party</label>
                    <select id="f_resp" name="responsible_party" class="filter-select">
                        <option value="">All</option>
                        <?php foreach (['Manufacturer', 'EPC', 'Carrier', 'Other'] as $rp): ?>
                            <option value="<?php echo $rp; ?>" <?php echo ($filters['responsible_party']===$rp)?'selected':''; ?>>
                                <?php echo $rp; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group status-dropdown">
                    <label class="filter-label">Status</label>
                    <div class="status-select" id="statusSelect">
                        <span class="label" id="statusLabel">All statuses</span>
                        <span>▾</span>
                    </div>
                    <div class="status-panel" id="statusPanel">
                        <?php 
                        $statuses = warrantyStatusPath();
                        foreach ($statuses as $st):
                            $checked = in_array($st, (array)$filters['statuses'], true) ? 'checked' : '';
                        ?>
                        <label class="status-item">
                            <input type="checkbox" class="form-check-input" value="<?php echo $st; ?>" <?php echo $checked; ?>>
                            <span><?php echo $st; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="statuses[]" id="status_hidden_1" value="<?php echo htmlspecialchars((string)(($filters['statuses'][0] ?? ''))); ?>">
                    <input type="hidden" name="statuses[]" id="status_hidden_2" value="<?php echo htmlspecialchars((string)(($filters['statuses'][1] ?? ''))); ?>">
                    <input type="hidden" name="statuses[]" id="status_hidden_3" value="<?php echo htmlspecialchars((string)(($filters['statuses'][2] ?? ''))); ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="f_from">Date From</label>
                    <input type="date" class="filter-input" id="f_from" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label" for="f_to">Date To</label>
                    <input type="date" class="filter-input" id="f_to" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                </div>

                <div class="filter-group">
                    <label class="filter-label">&nbsp;</label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="f_hide" name="hide_closed" value="1" <?php echo ((int)$filters['hide_closed']===1)?'checked':''; ?>>
                        <label class="form-check-label" for="f_hide">Hide Closed Tickets</label>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Pagination Controls (Custom, matching view_project.php) -->
    <div class="pagination-container">
        <div class="pagination-info">
            <span id="paginationInfo">Showing 0 of 0 claims</span>
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

    <!-- Table Container - EXACT MATCH to sustainability -->
    <div class="table-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-exclamation-triangle"></i>
                Warranty Claims
            </h3>
            <div class="table-header-actions">
                <button type="button" class="btn-export-header" onclick="exportCSV()">
                    <i class="fas fa-download"></i>
                    Export CSV
                </button>
                <div class="column-chooser-wrapper">
                    <button type="button" class="btn-columns-header" onclick="toggleColumnChooser()">
                        <i class="fas fa-columns"></i>
                        Columns
                    </button>
                    <div class="column-chooser-content" id="columnChooser">
                        <div class="column-chooser-header">Select Columns to Show:</div>
                        <div class="column-chooser-options">
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-ticket" checked> Ticket #</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-project" checked> Project</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-issue" checked> Issue Type</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-party" checked> Responsible Party</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-status" checked> Status</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-replacement" checked> Replacement Details</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-est-delivery" checked> Estimated Delivery</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-actual-delivery" checked> Actual Delivery</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-opened" checked> Opened</label>
                            </div>
                            <div class="column-item">
                                <label><input type="checkbox" class="column-toggle" data-column="col-updated" checked> Last Update</label>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (isAdminRole()): ?>
                <button type="button" class="btn-new-ticket-header" onclick="window.location='<?php echo 'warranty_create.php' . (!empty($filters['project_id']) ? ('?project_id='.(int)$filters['project_id']) : ''); ?>'">
                    <i class="fas fa-plus"></i>
                    New Ticket
                </button>
                <?php endif; ?>
            </div>
        </div>

        <table id="claimsTable" class="table">
            <thead>
                <tr>
                    <th class="col-ticket">Ticket #</th>
                    <th class="col-project">Project</th>
                    <th class="col-issue">Issue Type</th>
                    <th class="col-party">Responsible Party</th>
                    <th class="col-status">Status</th>
                    <th class="col-replacement">Replacement Details</th>
                    <th class="col-est-delivery">Estimated Delivery</th>
                    <th class="col-actual-delivery">Actual Delivery</th>
                    <th class="col-opened">Opened</th>
                    <th class="col-updated">Last Update</th>
                    <th class="col-action">Action</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
let claimsTable;
const NEW_TICKET_URL = <?php 
  $newUrl = 'warranty_create.php' . (!empty($filters['project_id']) ? ('?project_id='.(int)$filters['project_id']) : '');
  echo json_encode($newUrl);
?>;

function buildAjaxData(d) {
  const form = document.getElementById('filtersForm');
  const fd = new FormData(form);
  const issueVals = [
    (document.getElementById('issue_hidden_1')?.value || ''),
    (document.getElementById('issue_hidden_2')?.value || '')
  ].filter(Boolean);
  const statusVals = [
    (document.getElementById('status_hidden_1')?.value || ''),
    (document.getElementById('status_hidden_2')?.value || ''),
    (document.getElementById('status_hidden_3')?.value || '')
  ].filter(Boolean);
  d.project_id = parseInt(fd.get('project_id') || '0');
  d.issue_types = issueVals;
  d.responsible_party = fd.get('responsible_party') || '';
  d.statuses = statusVals;
  d.date_from = fd.get('date_from') || '';
  d.date_to = fd.get('date_to') || '';
  d.hide_closed = document.getElementById('f_hide').checked ? 1 : 0;
}

function statusBadge(status) {
  return `<span class="status-badge status-${status.replace(/ /g,'\\ ').replace(/-/g,'\\ - ')}">${status}</span>`;
}

function resolutionBadge(type, value) {
  if (type === 'Credit') {
    return `<span class="resolution-badge credit">Credit: $${value}</span>`;
  } else if (type === 'Replacement') {
    const colors = {
      'At Manufacturer': '#f39c12',
      'On Water': '#17a2b8', 
      'Cleared Customs': '#28a745',
      'In Transit to Warehouse': '#6c757d',
      'In Warehouse': '#dc3545',
      'In Transit to Project': '#17a2b8',
      'Delivered to Project': '#28a745'
    };
    const color = colors[value] || '#6c757d';
    return `<span class="resolution-badge replacement" style="background: ${color}20; color: ${color}; border: 1px solid ${color}40; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600;">${value}</span>`;
  }
  return '-';
}

// Clear filters function
function clearFilters() {
    document.getElementById('filtersForm').reset();
    window.location.href = '?';
}

// Toggle column chooser
function toggleColumnChooser() {
    const dropdown = document.getElementById('columnChooser');
    dropdown.classList.toggle('show');
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

  // Export CSV function
  function exportCSV() {
      alert('CSV export functionality would export current filtered data');
      // TODO: Implement CSV export with current filters
  }

  document.addEventListener('DOMContentLoaded', function() {
  claimsTable = $('#claimsTable').DataTable({
    processing: true,
    serverSide: true,
    order: [[9, 'desc']],
    ajax: { url: 'get_warranty_claims.php', data: function(d){ buildAjaxData(d); } },
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
    // Use custom external pagination controls; hide built-in length/pager/info
    dom: 'rt',
    columns: [
      { data: 0, className: 'col-ticket' },
      { data: 1, className: 'col-project' },
      { data: 2, className: 'col-issue', render: data => `<span class="filter-chip">${(data||'').replace('_',' ')}</span>` },
      { data: 3, className: 'col-party' },
      { data: 4, className: 'col-status', render: data => statusBadge(data) },
      { data: 5, className: 'col-replacement', render: function(data, type, row) {
          if ((row[4].includes('Approved') || row[4] === 'Closed') && row[11]) {
            if (row[11] === 'Credit' && row[12] && parseFloat(row[12]) > 0) {
              return resolutionBadge('Credit', parseFloat(row[12]).toFixed(2));
            } else if (row[11] === 'Replacement' && row[13]) {
              return resolutionBadge('Replacement', row[13]);
            } else if (row[11] === 'Replacement') {
              return `<span class="resolution-badge replacement" style="background: #6c757d20; color: #6c757d; border: 1px solid #6c757d40; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600;">Replacement</span>`;
            }
          }
          return '-';
        }},
      { data: 6, className: 'col-est-delivery', render: function(data, type, row) {
          if ((row[4].includes('Approved') || row[4] === 'Closed') && row[11] === 'Replacement' && data) {
            const parts = data.split('-');
            if (parts.length === 3) {
              const date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
              return date.toLocaleDateString();
            }
          }
          return '-';
        }},
      { data: 14, className: 'col-actual-delivery', render: function(data, type, row) {
          if ((row[4].includes('Approved') || row[4] === 'Closed') && row[11] === 'Replacement' && row[13] === 'Delivered to Project' && data) {
            const parts = data.split('-');
            if (parts.length === 3) {
              const date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
              return date.toLocaleDateString();
            }
          }
          return '-';
        }},
      { data: 8, className: 'col-opened', render: function(data, type, row) {
          if (data) {
            const date = new Date(String(data).replace(' ', 'T'));
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
          }
          return '-';
        }},
      { data: 9, className: 'col-updated', render: function(data, type, row) {
          if (data) {
            const date = new Date(String(data).replace(' ', 'T'));
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
          }
          return '-';
        }},
      { data: null, className: 'col-action', orderable:false, searchable:false, render: (row)=> `<button class="icon-btn" title="Open" onclick="event.stopPropagation(); window.location='warranty_detail.php?id=${row[0]}'">▶</button>` }
    ]
  });

  // Stats refresher to match filtered view
  function refreshStats() {
    const form = document.getElementById('filtersForm');
    const fd = new FormData(form);
    const params = new URLSearchParams();
    params.set('project_id', fd.get('project_id') || '0');
    const issueVals = [document.getElementById('issue_hidden_1')?.value, document.getElementById('issue_hidden_2')?.value].filter(Boolean);
    issueVals.forEach(v => params.append('issue_types[]', v));
    const statusVals = [document.getElementById('status_hidden_1')?.value, document.getElementById('status_hidden_2')?.value, document.getElementById('status_hidden_3')?.value].filter(Boolean);
    statusVals.forEach(v => params.append('statuses[]', v));
    params.set('responsible_party', fd.get('responsible_party') || '');
    params.set('date_from', fd.get('date_from') || '');
    params.set('date_to', fd.get('date_to') || '');
    params.set('hide_closed', document.getElementById('f_hide').checked ? '1' : '0');

    fetch('get_warranty_stats.php?' + params.toString(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(s => {
        if (!s) return;
        const fmt = (n) => (new Intl.NumberFormat().format(parseInt(n || 0, 10)));
        const elTotal = document.getElementById('stat-total');
        const elClosed = document.getElementById('stat-closed');
        const elPending = document.getElementById('stat-pending');
        const elApproved = document.getElementById('stat-approved');
        if (elTotal) elTotal.textContent = fmt(s.total_tickets || 0);
        if (elClosed) elClosed.textContent = fmt(s.closed_tickets || 0);
        if (elPending) elPending.textContent = fmt(s.pending_tickets || 0);
        if (elApproved) elApproved.textContent = fmt(s.approved_tickets || 0);
      })
      .catch(() => {});
  }

  // External pagination controls wired to DataTables
  const itemsPerPageInput = document.getElementById('itemsPerPage');
  const prevButton = document.getElementById('prevPage');
  const nextButton = document.getElementById('nextPage');
  const paginationInfo = document.getElementById('paginationInfo');
  const pageInfo = document.getElementById('pageInfo');

  function updatePaginationUI() {
    if (!claimsTable) return;
    const info = claimsTable.page.info();
    if (!info) return;
    // Update info text
    const total = info.recordsDisplay ?? info.recordsTotal ?? 0;
    const start = total ? (info.start + 1) : 0;
    const end = info.end ?? 0;
    if (paginationInfo) {
      paginationInfo.textContent = `Showing ${start}-${end} of ${total} claims`;
    }
    // Update page text
    if (pageInfo) {
      const current = (info.page ?? 0) + 1;
      const pages = Math.max(info.pages ?? 1, 1);
      pageInfo.textContent = `Page ${current} of ${pages}`;
    }
    // Update buttons
    if (prevButton) prevButton.disabled = (info.page ?? 0) <= 0;
    if (nextButton) nextButton.disabled = (info.pages ?? 0) === 0 || (info.page ?? 0) >= ((info.pages ?? 1) - 1);
    // Sync itemsPerPage input
    if (itemsPerPageInput) itemsPerPageInput.value = claimsTable.page.len();
  }

  if (itemsPerPageInput) {
    itemsPerPageInput.addEventListener('change', function(){
      let val = parseInt(this.value, 10);
      if (isNaN(val) || val < 1) val = 1;
      if (val > 500) val = 500;
      this.value = val;
      claimsTable.page.len(val).draw(false);
      updatePaginationUI();
    });
  }
  if (prevButton) {
    prevButton.addEventListener('click', function(){
      claimsTable.page('previous').draw('page');
      updatePaginationUI();
    });
  }
  if (nextButton) {
    nextButton.addEventListener('click', function(){
      claimsTable.page('next').draw('page');
      updatePaginationUI();
    });
  }

  // Update pagination UI on table events
  claimsTable.on('draw', updatePaginationUI);
  claimsTable.on('xhr', updatePaginationUI);
  updatePaginationUI();
  // Initial stats sync
  refreshStats();

  $('#claimsTable tbody').on('click', 'tr', function() {
    const data = claimsTable.row(this).data();
    if (!data) return;
    window.location = `warranty_detail.php?id=${data[0]}`;
  });

  document.getElementById('applyFilters').addEventListener('click', function(){
    const params = new URLSearchParams();
    const fd = new FormData(document.getElementById('filtersForm'));
    params.set('project_id', fd.get('project_id') || '0');
    const issueVals = [document.getElementById('issue_hidden_1')?.value, document.getElementById('issue_hidden_2')?.value].filter(Boolean);
    issueVals.forEach(v => params.append('issue_types[]', v));
    const statusVals = [document.getElementById('status_hidden_1')?.value, document.getElementById('status_hidden_2')?.value, document.getElementById('status_hidden_3')?.value].filter(Boolean);
    statusVals.forEach(v => params.append('statuses[]', v));
    params.set('responsible_party', fd.get('responsible_party') || '');
    params.set('date_from', fd.get('date_from') || '');
    params.set('date_to', fd.get('date_to') || '');
    params.set('hide_closed', document.getElementById('f_hide').checked ? '1' : '0');
    const newUrl = window.location.pathname + '?' + params.toString();
    window.history.replaceState({}, '', newUrl);
    claimsTable.ajax.reload();
    refreshStats();
  });
  
  // Issue Type dropdown
  const issueSelect = document.getElementById('issueSelect');
  const issuePanel = document.getElementById('issuePanel');
  const issueLabel = document.getElementById('issueLabel');
  const issueHidden = [document.getElementById('issue_hidden_1'), document.getElementById('issue_hidden_2')];
  
  function currentIssues(){
    return Array.from(issuePanel.querySelectorAll('input[type="checkbox"]:checked')).map(i=>i.value).slice(0,2);
  }
  
  function updateIssueHidden(){
    const vals = currentIssues();
    issueHidden.forEach((inp, idx)=> inp.value = vals[idx] || '');
    issueLabel.textContent = vals.length ? vals.map(v => ({damaged:'Damaged','quantity_discrepancy':'Quantity Discrepancy', both:'Both'}[v])).join(', ') : 'All types';
  }
  
  issueSelect.addEventListener('click', ()=> {
    issuePanel.classList.toggle('open');
    const sp = document.getElementById('statusPanel');
    if (sp) sp.classList.remove('open');
  });
  
  issuePanel.addEventListener('change', updateIssueHidden);
  
  document.addEventListener('click', (e)=>{
    if (!issuePanel.contains(e.target) && !issueSelect.contains(e.target)) issuePanel.classList.remove('open');
  });
  
  updateIssueHidden();

  // Status dropdown
  const statusSelect = document.getElementById('statusSelect');
  const statusPanel = document.getElementById('statusPanel');
  const statusLabel = document.getElementById('statusLabel');
  const statusHidden = [
    document.getElementById('status_hidden_1'),
    document.getElementById('status_hidden_2'),
    document.getElementById('status_hidden_3')
  ];
  
  function currentStatuses(){
    return Array.from(statusPanel.querySelectorAll('input[type="checkbox"]:checked')).map(i=>i.value).slice(0,3);
  }
  
  function updateStatusHidden(){
    const vals = currentStatuses();
    statusHidden.forEach((inp, idx)=> inp.value = vals[idx] || '');
    statusLabel.textContent = vals.length ? vals.join(', ') : 'All statuses';
  }
  
  statusSelect.addEventListener('click', ()=>{
    statusPanel.classList.toggle('open');
    const ip = document.getElementById('issuePanel');
    if (ip) ip.classList.remove('open');
  });
  
  statusPanel.addEventListener('change', updateStatusHidden);
  
  document.addEventListener('click', (e)=>{
    if (!statusPanel.contains(e.target) && !statusSelect.contains(e.target)) {
      statusPanel.classList.remove('open');
    }
  });
  
  updateStatusHidden();

  // Column chooser functionality
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
      columnChooser.classList.remove('show');
    }
  });
});
</script>
</body>
</html>
