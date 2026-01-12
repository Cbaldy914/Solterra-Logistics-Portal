<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized.php"); // Redirect to an unauthorized page
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$warehouses = [];
$errorMessage = '';
$successMessage = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $warehouse_id = intval($_GET['id']);

        // Check if warehouse is being used anywhere (optional - you can add checks later)
        // For now, we'll allow deletion

        $stmt = $conn->prepare("DELETE FROM warehouses WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }

        $stmt->bind_param("i", $warehouse_id);
        if ($stmt->execute()) {
            $successMessage = "Warehouse deleted successfully.";
        } else {
            throw new Exception("Error deleting warehouse: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

try {
    // Fetch warehouses along with a count of pallets currently stored and in transit
    // Using subqueries to avoid cartesian products from multiple JOINs
    $sql = "SELECT
                w.id,
                w.name,
                w.address,
                w.image_url,
                w.is_port,
                (SELECT COUNT(*) FROM inventory_pallets ip
                 WHERE ip.current_warehouse_id = w.id AND ip.status = 'In Warehouse') AS current_pallet_count,
                (SELECT COALESCE(SUM(ip.quantity), 0) FROM inventory_pallets ip
                 WHERE ip.current_warehouse_id = w.id AND ip.status = 'In Warehouse') AS total_modules_stored,
                (SELECT COUNT(DISTINCT ip.id) FROM deliveries d
                 JOIN delivery_pallets dp ON d.id = dp.delivery_id
                 JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
                 WHERE d.warehouse_id = w.id AND ip.status = 'In Transit to Warehouse') AS in_transit_pallet_count
            FROM warehouses w
            ORDER BY w.name ASC";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Fetch cost items for this warehouse
            $row['cost_items'] = [];
            $stmtCosts = $conn->prepare("
                SELECT id, label, amount, trigger_event, unit_type, pallets_per_truck, sqft_per_pallet
                FROM warehouse_cost_items
                WHERE warehouse_id = ? AND is_active = 1
                ORDER BY display_order ASC, trigger_event ASC
            ");
            if ($stmtCosts) {
                $stmtCosts->bind_param("i", $row['id']);
                $stmtCosts->execute();
                $costResult = $stmtCosts->get_result();
                while ($cost = $costResult->fetch_assoc()) {
                    $row['cost_items'][] = $cost;
                }
                $stmtCosts->close();
            }
            $warehouses[] = $row;
        }
    } else {
        throw new Exception("Error fetching warehouses: " . $conn->error);
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();

// Calculate totals for stats
$total_ports = 0;
$total_regular_warehouses = 0;
$total_pallets = 0;
$total_modules = 0;
foreach ($warehouses as $wh) {
    if (!empty($wh['is_port'])) {
        $total_ports++;
    } else {
        $total_regular_warehouses++;
    }
    $total_pallets += $wh['current_pallet_count'];
    $total_modules += $wh['total_modules_stored'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Warehouses</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modern Page Header */
        .warehouses-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .warehouses-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .warehouses-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .warehouses-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .warehouses-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .btn-add-warehouse {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-add-warehouse:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
            color: white;
        }

        /* Stats Section */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .stat-card.clickable {
            cursor: pointer;
        }
        .stat-card.clickable:hover {
            border-color: #488C9A;
        }
        .stat-card.active {
            border-color: #488C9A;
            background: linear-gradient(135deg, #e8f4f8 0%, #ffffff 100%);
        }
        .stat-card.active .stat-icon {
            color: #293E4C;
        }
        .stat-icon {
            font-size: 2em;
            margin-bottom: 8px;
            color: #488C9A;
        }
        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #293E4C;
            margin: 0;
        }
        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            margin: 4px 0 0;
            font-weight: 500;
        }

        /* Section Header with Controls */
        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .section-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }
        .section-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .view-toggle {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 8px;
            padding: 3px;
            gap: 3px;
        }
        .view-toggle button {
            padding: 8px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 1em;
            border: none;
            cursor: pointer;
            color: #6c757d;
            background: transparent;
            transition: all 0.2s;
        }
        .view-toggle button:hover {
            color: #293E4C;
            background: rgba(255,255,255,0.5);
        }
        .view-toggle button.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        /* Messages */
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px 20px;
            border: 1px solid #f5c6cb;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px 20px;
            border: 1px solid #c3e6cb;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        /* Warehouse Cards Grid */
        .warehouses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .warehouses-grid:not(.active) { display: none; }
        .warehouses-grid.active { display: grid; }

        /* Override portal.css .warehouse-card width */
        .warehouses-grid .warehouse-card {
            width: 100% !important;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.2s;
        }
        .warehouse-card.filtered-out {
            display: none !important;
        }
        .warehouses-grid .warehouse-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0,0,0,0.12);
        }
        .warehouse-card-image {
            width: 100%;
            height: 160px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .warehouse-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .warehouse-card-image .placeholder-icon {
            font-size: 4rem;
            color: #488C9A;
            opacity: 0.5;
        }
        .warehouse-card-badges {
            position: absolute;
            top: 12px;
            left: 12px;
            display: flex;
            gap: 8px;
        }
        .port-badge {
            background: linear-gradient(135deg, #007cba 0%, #0056b3 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .warehouse-card-content {
            padding: 20px;
        }
        .warehouse-card-header {
            margin-bottom: 12px;
        }
        .warehouse-card-name {
            font-size: 1.15em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 4px;
        }
        .warehouse-card-address {
            font-size: 0.85em;
            color: #6c757d;
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        .warehouse-card-address i {
            margin-top: 2px;
            color: #488C9A;
        }
        .warehouse-card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 16px 0;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .card-stat {
            text-align: center;
        }
        .card-stat-value {
            font-size: 1.3em;
            font-weight: 700;
            color: #293E4C;
        }
        .card-stat-label {
            font-size: 0.75em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .warehouse-card-fees {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: #e8f4f8;
            border-radius: 8px;
            margin-bottom: 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .warehouse-card-fees:hover {
            background: #d4ebf2;
        }
        .fees-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            color: #0c5460;
        }
        .fees-summary i {
            color: #488C9A;
        }
        .fees-view-more {
            font-size: 0.8em;
            color: #488C9A;
            font-weight: 500;
        }
        .no-fees {
            background: #f8f9fa;
            color: #6c757d;
        }
        .no-fees:hover {
            background: #e9ecef;
        }
        .warehouse-card-actions {
            display: flex;
            gap: 8px;
        }
        .card-btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .card-btn.primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
        }
        .card-btn.primary:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
        }
        .card-btn.secondary {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #e9ecef;
        }
        .card-btn.secondary:hover {
            background: #e9ecef;
            color: #293E4C;
        }
        .card-btn.danger {
            background: #fff;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        .card-btn.danger:hover {
            background: #dc3545;
            color: #fff;
        }

        /* Table View */
        .warehouses-table-container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            display: none;
        }
        .warehouses-table-container.active { display: block; }
        .warehouses-table {
            width: 100%;
            border-collapse: collapse;
        }
        .warehouses-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #fff;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .warehouses-table td {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .warehouses-table tbody tr {
            transition: background 0.2s;
        }
        .warehouses-table tbody tr:hover {
            background: #f8f9fa;
        }
        .warehouses-table tbody tr:last-child td {
            border-bottom: none;
        }
        .table-warehouse-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .table-warehouse-image {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #f8f9fa;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .table-warehouse-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .table-warehouse-image i {
            color: #488C9A;
            font-size: 1.2em;
        }
        .table-warehouse-details {
            flex: 1;
        }
        .table-warehouse-name {
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-warehouse-address {
            font-size: 0.85em;
            color: #6c757d;
        }
        .table-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7em;
            font-weight: 600;
        }
        .table-badge.port {
            background: #cce5ff;
            color: #004085;
        }
        .table-inventory-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            background: #f8f9fa;
            color: #293E4C;
            border-radius: 20px;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e9ecef;
        }
        .table-inventory-btn:hover {
            background: #e8f4f8;
            border-color: #488C9A;
        }
        .table-inventory-btn i {
            color: #488C9A;
        }
        .table-inventory-btn .pallet-count {
            font-weight: 600;
        }
        .table-fees-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #e8f4f8;
            color: #0c5460;
            border-radius: 20px;
            font-size: 0.85em;
            cursor: pointer;
            transition: background 0.2s;
            border: none;
        }
        .table-fees-btn:hover {
            background: #d4ebf2;
        }
        .table-fees-btn.no-fees {
            background: #f8f9fa;
            color: #6c757d;
        }
        .table-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .warehouse-row.filtered-out {
            display: none !important;
        }
        .table-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .table-action-btn.view {
            background: #488C9A;
            color: white;
        }
        .table-action-btn.view:hover {
            background: #3a7a87;
        }
        .table-action-btn.edit {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #e9ecef;
        }
        .table-action-btn.edit:hover {
            background: #488C9A;
            color: white;
            border-color: #488C9A;
        }
        .table-action-btn.delete {
            background: #fff;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }
        .table-action-btn.delete:hover {
            background: #dc3545;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        }
        .empty-state-icon {
            font-size: 4rem;
            color: #488C9A;
            opacity: 0.4;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            color: #293E4C;
            margin: 0 0 8px;
        }
        .empty-state p {
            color: #6c757d;
            margin: 0 0 24px;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background: #fff;
            border-radius: 20px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            transform: scale(0.9);
            transition: transform 0.3s;
        }
        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        .modal-header h3 {
            margin: 0;
            color: #293E4C;
            font-size: 1.2em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-header h3 i {
            color: #488C9A;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #6c757d;
            padding: 0;
            line-height: 1;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .modal-close:hover {
            background: #f8f9fa;
            color: #293E4C;
        }
        .modal-body {
            padding: 24px;
            max-height: 50vh;
            overflow-y: auto;
        }
        .fee-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .fee-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #488C9A;
        }
        .fee-item.entry { border-left-color: #28a745; }
        .fee-item.exit { border-left-color: #dc3545; }
        .fee-item.monthly { border-left-color: #ffc107; }
        .fee-item.customs_clearance { border-left-color: #007cba; }
        .fee-item.drayage { border-left-color: #6f42c1; }
        .fee-info {
            flex: 1;
        }
        .fee-name {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 2px;
        }
        .fee-trigger {
            font-size: 0.8em;
            color: #6c757d;
            text-transform: capitalize;
        }
        .fee-amount {
            font-size: 1.1em;
            font-weight: 700;
            color: #488C9A;
        }
        .fee-unit {
            font-size: 0.75em;
            color: #6c757d;
            font-weight: 400;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .modal-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .modal-btn.primary {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            border: none;
        }
        .modal-btn.primary:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
        }
        .modal-btn.secondary {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #e9ecef;
        }
        .modal-btn.secondary:hover {
            background: #e9ecef;
        }

        .no-fees-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .no-fees-message i {
            font-size: 3em;
            opacity: 0.3;
            margin-bottom: 12px;
            display: block;
        }

        /* Inventory Modal Styles */
        .inventory-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .inventory-stat-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .inventory-stat-card.pallets {
            border-left: 4px solid #488C9A;
        }
        .inventory-stat-card.modules {
            border-left: 4px solid #28a745;
        }
        .inventory-stat-card.mw {
            border-left: 4px solid #ffc107;
        }
        .inventory-stat-icon {
            font-size: 1.5em;
            margin-bottom: 8px;
            color: #488C9A;
        }
        .inventory-stat-card.modules .inventory-stat-icon {
            color: #28a745;
        }
        .inventory-stat-card.mw .inventory-stat-icon {
            color: #ffc107;
        }
        .inventory-stat-value {
            font-size: 1.8em;
            font-weight: 700;
            color: #293E4C;
            margin: 4px 0;
        }
        .inventory-stat-label {
            font-size: 0.85em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .no-inventory-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .no-inventory-message i {
            font-size: 3em;
            opacity: 0.3;
            margin-bottom: 12px;
            display: block;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .warehouses-header-content { flex-direction: column; align-items: flex-start; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .section-header-row { flex-direction: column; gap: 12px; align-items: flex-start; }
            .warehouses-grid { grid-template-columns: 1fr; }
            .warehouses-table-container { overflow-x: auto; }
            .warehouses-table { min-width: 800px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Warehouses', 'ref_map' => []]); ?>

    <!-- Modern Page Header -->
    <div class="warehouses-header">
        <div class="warehouses-header-content">
            <div>
                <h1>Warehouse Management</h1>
                <p class="subtitle">Manage your warehouse facilities and fee structures</p>
            </div>
            <a href="add_warehouse.php" class="btn-add-warehouse">
                <i class="fas fa-plus"></i> Add Warehouse
            </a>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card clickable" onclick="filterWarehouses('warehouse')" id="stat-warehouses">
            <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
            <h3 class="stat-number"><?php echo $total_regular_warehouses; ?></h3>
            <p class="stat-label">Warehouses</p>
        </div>
        <div class="stat-card clickable" onclick="filterWarehouses('port')" id="stat-ports">
            <div class="stat-icon"><i class="fas fa-anchor"></i></div>
            <h3 class="stat-number"><?php echo $total_ports; ?></h3>
            <p class="stat-label">Ports</p>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-pallet"></i></div>
            <h3 class="stat-number"><?php echo number_format($total_pallets); ?></h3>
            <p class="stat-label">Pallets Stored</p>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-solar-panel"></i></div>
            <h3 class="stat-number"><?php echo number_format($total_modules); ?></h3>
            <p class="stat-label">Modules Stored</p>
        </div>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
        </div>
    <?php endif; ?>

    <!-- Section Header with View Toggle -->
    <div class="section-header-row">
        <h2 class="section-title">All Warehouses</h2>
        <div class="section-controls">
            <div class="view-toggle">
                <button class="active" onclick="setView('grid')" id="btn-grid" title="Card View">
                    <i class="fas fa-th-large"></i>
                </button>
                <button onclick="setView('table')" id="btn-table" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>
    </div>

    <?php if (!empty($warehouses)): ?>
        <!-- Card View -->
        <div class="warehouses-grid active" id="warehouses-grid">
            <?php foreach ($warehouses as $warehouse): ?>
                <?php
                    $has_image = !empty($warehouse['image_url']) && file_exists(__DIR__ . '/' . $warehouse['image_url']);
                    $fee_count = count($warehouse['cost_items']);
                    $warehouse_type = !empty($warehouse['is_port']) ? 'port' : 'warehouse';
                ?>
                <div class="warehouse-card" data-type="<?php echo $warehouse_type; ?>">
                    <div class="warehouse-card-image">
                        <?php if ($has_image): ?>
                            <img src="<?php echo htmlspecialchars($warehouse['image_url']); ?>" alt="<?php echo htmlspecialchars($warehouse['name']); ?>">
                        <?php else: ?>
                            <i class="fas fa-warehouse placeholder-icon"></i>
                        <?php endif; ?>
                        <?php if (!empty($warehouse['is_port'])): ?>
                            <div class="warehouse-card-badges">
                                <span class="port-badge"><i class="fas fa-anchor"></i> Port</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="warehouse-card-content">
                        <div class="warehouse-card-header">
                            <h3 class="warehouse-card-name"><?php echo htmlspecialchars($warehouse['name']); ?></h3>
                            <div class="warehouse-card-address">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo htmlspecialchars($warehouse['address'] ?: 'No address'); ?></span>
                            </div>
                        </div>

                        <div class="warehouse-card-stats">
                            <div class="card-stat">
                                <div class="card-stat-value"><?php echo number_format($warehouse['current_pallet_count']); ?></div>
                                <div class="card-stat-label">Pallets</div>
                            </div>
                            <div class="card-stat">
                                <div class="card-stat-value"><?php echo number_format($warehouse['total_modules_stored']); ?></div>
                                <div class="card-stat-label">Modules</div>
                            </div>
                        </div>

                        <div class="warehouse-card-fees <?php echo $fee_count === 0 ? 'no-fees' : ''; ?>" onclick="openFeeModal(<?php echo $warehouse['id']; ?>)">
                            <span class="fees-summary">
                                <i class="fas fa-dollar-sign"></i>
                                <?php if ($fee_count > 0): ?>
                                    <?php echo $fee_count; ?> fee<?php echo $fee_count !== 1 ? 's' : ''; ?> configured
                                <?php else: ?>
                                    No fees configured
                                <?php endif; ?>
                            </span>
                            <span class="fees-view-more"><?php echo $fee_count > 0 ? 'View details' : 'Add fees'; ?> <i class="fas fa-chevron-right"></i></span>
                        </div>

                        <div class="warehouse-card-actions">
                            <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse['id']; ?>" class="card-btn primary">
                                <i class="fas fa-boxes"></i> Inventory
                            </a>
                            <a href="edit_warehouse.php?id=<?php echo $warehouse['id']; ?>" class="card-btn secondary">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button class="card-btn danger" onclick="confirmDelete('<?php echo htmlspecialchars($warehouse['name'], ENT_QUOTES); ?>', <?php echo $warehouse['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Table View -->
        <div class="warehouses-table-container" id="warehouses-table">
            <table class="warehouses-table">
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Inventory</th>
                        <th>Fees</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <?php
                            $has_image = !empty($warehouse['image_url']) && file_exists(__DIR__ . '/' . $warehouse['image_url']);
                            $fee_count = count($warehouse['cost_items']);
                            $warehouse_type = !empty($warehouse['is_port']) ? 'port' : 'warehouse';
                        ?>
                        <tr data-type="<?php echo $warehouse_type; ?>" class="warehouse-row">
                            <td>
                                <div class="table-warehouse-info">
                                    <div class="table-warehouse-image">
                                        <?php if ($has_image): ?>
                                            <img src="<?php echo htmlspecialchars($warehouse['image_url']); ?>" alt="">
                                        <?php else: ?>
                                            <i class="fas fa-warehouse"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="table-warehouse-details">
                                        <div class="table-warehouse-name">
                                            <?php echo htmlspecialchars($warehouse['name']); ?>
                                            <?php if (!empty($warehouse['is_port'])): ?>
                                                <span class="table-badge port"><i class="fas fa-anchor"></i> Port</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="table-warehouse-address"><?php echo htmlspecialchars($warehouse['address'] ?: 'No address'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <button class="table-inventory-btn" onclick="openInventoryModal(<?php echo $warehouse['id']; ?>)">
                                    <i class="fas fa-pallet"></i>
                                    <span class="pallet-count"><?php echo number_format($warehouse['current_pallet_count']); ?></span> pallets
                                </button>
                            </td>
                            <td>
                                <button class="table-fees-btn <?php echo $fee_count === 0 ? 'no-fees' : ''; ?>" onclick="openFeeModal(<?php echo $warehouse['id']; ?>)">
                                    <i class="fas fa-dollar-sign"></i>
                                    <?php echo $fee_count > 0 ? $fee_count . ' fee' . ($fee_count !== 1 ? 's' : '') : 'None'; ?>
                                </button>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a href="manage_warehouse_inventory.php?warehouse_id=<?php echo $warehouse['id']; ?>" class="table-action-btn view" title="View Inventory">
                                        <i class="fas fa-boxes"></i>
                                    </a>
                                    <a href="edit_warehouse.php?id=<?php echo $warehouse['id']; ?>" class="table-action-btn edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button class="table-action-btn delete" onclick="confirmDelete('<?php echo htmlspecialchars($warehouse['name'], ENT_QUOTES); ?>', <?php echo $warehouse['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-warehouse"></i></div>
            <h3>No Warehouses Yet</h3>
            <p>Get started by adding your first warehouse facility.</p>
            <a href="add_warehouse.php" class="btn-add-warehouse">
                <i class="fas fa-plus"></i> Add Warehouse
            </a>
        </div>
    <?php endif; ?>

    <!-- Fee Details Modal -->
    <div class="modal-overlay" id="feeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-dollar-sign"></i> <span id="modalWarehouseName">Fee Structure</span></h3>
                <button class="modal-close" onclick="closeFeeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalFeeList">
                <!-- Fee items will be inserted here -->
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeFeeModal()">Close</button>
                <a href="#" class="modal-btn primary" id="modalEditBtn">
                    <i class="fas fa-edit"></i> Edit Fees
                </a>
            </div>
        </div>
    </div>

    <!-- Inventory Details Modal -->
    <div class="modal-overlay" id="inventoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> <span id="inventoryModalWarehouseName">Inventory</span></h3>
                <button class="modal-close" onclick="closeInventoryModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalInventoryContent">
                <!-- Inventory stats will be inserted here -->
            </div>
            <div class="modal-footer">
                <button class="modal-btn secondary" onclick="closeInventoryModal()">Close</button>
                <a href="#" class="modal-btn primary" id="inventoryModalViewBtn">
                    <i class="fas fa-boxes"></i> View Full Inventory
                </a>
            </div>
        </div>
    </div>
</main>

<script>
    // Warehouse data for modals
    const warehouseData = <?php echo json_encode($warehouses); ?>;

    // View toggle
    function setView(view) {
        const gridBtn = document.getElementById('btn-grid');
        const tableBtn = document.getElementById('btn-table');
        const grid = document.getElementById('warehouses-grid');
        const table = document.getElementById('warehouses-table');

        if (view === 'grid') {
            gridBtn.classList.add('active');
            tableBtn.classList.remove('active');
            grid.classList.add('active');
            table.classList.remove('active');
        } else {
            gridBtn.classList.remove('active');
            tableBtn.classList.add('active');
            grid.classList.remove('active');
            table.classList.add('active');
        }

        // Save preference
        localStorage.setItem('warehouseViewPreference', view);
    }

    // Load saved view preference
    document.addEventListener('DOMContentLoaded', function() {
        const savedView = localStorage.getItem('warehouseViewPreference');
        if (savedView) {
            setView(savedView);
        }
    });

    // Delete confirmation
    function confirmDelete(warehouseName, warehouseId) {
        if (confirm(`Are you sure you want to delete "${warehouseName}"?\n\nThis action cannot be undone.`)) {
            window.location.href = `manage_warehouses.php?action=delete&id=${warehouseId}`;
        }
    }

    // Fee Modal
    function openFeeModal(warehouseId) {
        const warehouse = warehouseData.find(w => w.id == warehouseId);
        if (!warehouse) return;

        document.getElementById('modalWarehouseName').textContent = warehouse.name + ' - Fees';
        document.getElementById('modalEditBtn').href = `edit_warehouse.php?id=${warehouseId}`;

        const feeListContainer = document.getElementById('modalFeeList');

        if (warehouse.cost_items && warehouse.cost_items.length > 0) {
            let html = '<div class="fee-list">';
            warehouse.cost_items.forEach(fee => {
                const triggerClass = fee.trigger_event || 'other';
                let unitSuffix = '/pallet';
                switch(fee.unit_type) {
                    case 'per_truck': unitSuffix = '/truck'; break;
                    case 'per_sqft': unitSuffix = '/sqft'; break;
                    case 'flat': unitSuffix = ' flat'; break;
                }
                const triggerLabel = fee.trigger_event ? fee.trigger_event.replace('_', ' ') : 'Other';

                html += `
                    <div class="fee-item ${triggerClass}">
                        <div class="fee-info">
                            <div class="fee-name">${escapeHtml(fee.label)}</div>
                            <div class="fee-trigger">${triggerLabel}</div>
                        </div>
                        <div class="fee-amount">
                            $${parseFloat(fee.amount).toFixed(2)}
                            <span class="fee-unit">${unitSuffix}</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            feeListContainer.innerHTML = html;
        } else {
            feeListContainer.innerHTML = `
                <div class="no-fees-message">
                    <i class="fas fa-receipt"></i>
                    <p>No fees configured for this warehouse.</p>
                    <p style="font-size: 0.9em;">Click "Edit Fees" to add a fee structure.</p>
                </div>
            `;
        }

        document.getElementById('feeModal').classList.add('active');
    }

    function closeFeeModal() {
        document.getElementById('feeModal').classList.remove('active');
    }

    // Close modal on overlay click
    document.getElementById('feeModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeFeeModal();
        }
    });

    // Inventory Modal
    function openInventoryModal(warehouseId) {
        const warehouse = warehouseData.find(w => w.id == warehouseId);
        if (!warehouse) return;

        document.getElementById('inventoryModalWarehouseName').textContent = warehouse.name + ' - Inventory';
        document.getElementById('inventoryModalViewBtn').href = `manage_warehouse_inventory.php?warehouse_id=${warehouseId}`;

        const contentContainer = document.getElementById('modalInventoryContent');
        const pallets = parseInt(warehouse.current_pallet_count) || 0;
        const modules = parseInt(warehouse.total_modules_stored) || 0;
        // Estimate MW based on typical module wattage (approx 550W per module)
        const mw = (modules * 0.00055).toFixed(3);

        if (pallets > 0 || modules > 0) {
            contentContainer.innerHTML = `
                <div class="inventory-stats">
                    <div class="inventory-stat-card pallets">
                        <div class="inventory-stat-icon"><i class="fas fa-pallet"></i></div>
                        <div class="inventory-stat-value">${pallets.toLocaleString()}</div>
                        <div class="inventory-stat-label">Pallets</div>
                    </div>
                    <div class="inventory-stat-card modules">
                        <div class="inventory-stat-icon"><i class="fas fa-solar-panel"></i></div>
                        <div class="inventory-stat-value">${modules.toLocaleString()}</div>
                        <div class="inventory-stat-label">Modules</div>
                    </div>
                    <div class="inventory-stat-card mw">
                        <div class="inventory-stat-icon"><i class="fas fa-bolt"></i></div>
                        <div class="inventory-stat-value">${mw}</div>
                        <div class="inventory-stat-label">MW (est.)</div>
                    </div>
                </div>
            `;
        } else {
            contentContainer.innerHTML = `
                <div class="no-inventory-message">
                    <i class="fas fa-box-open"></i>
                    <p>No inventory currently stored at this warehouse.</p>
                </div>
            `;
        }

        document.getElementById('inventoryModal').classList.add('active');
    }

    function closeInventoryModal() {
        document.getElementById('inventoryModal').classList.remove('active');
    }

    // Close inventory modal on overlay click
    document.getElementById('inventoryModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeInventoryModal();
        }
    });

    // Filter Warehouses
    let currentFilter = 'all';

    function filterWarehouses(type) {
        const statWarehouses = document.getElementById('stat-warehouses');
        const statPorts = document.getElementById('stat-ports');
        const cards = document.querySelectorAll('.warehouse-card');
        const rows = document.querySelectorAll('.warehouse-row');

        // Toggle filter - click again to clear
        if (currentFilter === type) {
            currentFilter = 'all';
            statWarehouses.classList.remove('active');
            statPorts.classList.remove('active');
        } else {
            currentFilter = type;
            statWarehouses.classList.toggle('active', type === 'warehouse');
            statPorts.classList.toggle('active', type === 'port');
        }

        // Apply filter to cards
        cards.forEach(card => {
            const cardType = card.getAttribute('data-type');
            if (currentFilter === 'all' || cardType === currentFilter) {
                card.classList.remove('filtered-out');
            } else {
                card.classList.add('filtered-out');
            }
        });

        // Apply filter to table rows
        rows.forEach(row => {
            const rowType = row.getAttribute('data-type');
            if (currentFilter === 'all' || rowType === currentFilter) {
                row.classList.remove('filtered-out');
            } else {
                row.classList.add('filtered-out');
            }
        });

        // Update section title
        const sectionTitle = document.querySelector('.section-title');
        if (currentFilter === 'warehouse') {
            sectionTitle.textContent = 'Warehouses';
        } else if (currentFilter === 'port') {
            sectionTitle.textContent = 'Ports';
        } else {
            sectionTitle.textContent = 'All Warehouses';
        }
    }

    // Close any modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFeeModal();
            closeInventoryModal();
        }
    });

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>

</body>
</html>
