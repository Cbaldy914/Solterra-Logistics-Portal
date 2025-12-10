<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);
    $stmt = $conn->prepare("DELETE FROM warehouse_estimates WHERE id = ? AND user_id = ? AND app_type = 'calculator'");
    $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);
    if ($stmt->execute()) {
        $_SESSION['calc_message'] = ['type' => 'success', 'text' => 'Estimate deleted successfully!'];
    } else {
        $_SESSION['calc_message'] = ['type' => 'error', 'text' => 'Error deleting estimate.'];
    }
    $stmt->close();
    header("Location: cost_estimate_calculator");
    exit();
}

// Fetch saved estimates for this user
$sql = "SELECT id, name, estimate_data, created_at FROM warehouse_estimates WHERE user_id = ? AND app_type = 'calculator' ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$saved_estimates = [];
while ($row = $result->fetch_assoc()) {
    $saved_estimates[] = $row;
}
$stmt->close();

// Check if we're loading an existing estimate for editing
$editing_estimate = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $stmt = $conn->prepare("SELECT * FROM warehouse_estimates WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $edit_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $editing_estimate = json_decode($row['estimate_data'], true);
        $editing_estimate['id'] = $row['id'];
        $editing_estimate['name'] = $row['name'];
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Cost Calculator</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern Page Header */
        .calculator-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .calculator-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .calculator-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .calculator-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .calculator-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .header-actions {
            display: flex;
            gap: 12px;
        }
        .btn-saved-estimates {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #fff;
            color: #488C9A;
            border: 2px solid #488C9A;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-saved-estimates:hover {
            background: #488C9A;
            color: #fff;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 32px;
            padding: 20px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .step-number {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .step.active .step-number {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
        }
        .step.completed .step-number {
            background: #28a745;
            color: #fff;
        }
        .step-label {
            font-weight: 500;
            color: #6c757d;
            font-size: 0.95rem;
        }
        .step.active .step-label {
            color: #293E4C;
        }
        .step-connector {
            width: 60px;
            height: 3px;
            background: #e9ecef;
            margin: 0 5px;
        }
        .step-connector.completed {
            background: #28a745;
        }

        /* Accordion Sections */
        .accordion-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid transparent;
        }
        .accordion-header:hover {
            background: #f8f9fa;
        }
        .accordion-header.active {
            border-bottom: 1px solid #e9ecef;
        }
        .accordion-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .accordion-header h2 .step-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .accordion-toggle {
            font-size: 1.5rem;
            color: #6c757d;
            transition: transform 0.3s ease;
        }
        .accordion-header.active .accordion-toggle {
            transform: rotate(180deg);
        }
        .accordion-content {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.3s ease;
        }
        .accordion-content.open {
            padding: 24px;
            max-height: 2000px;
        }

        /* Warehouse Cards - prefixed to avoid portal.css conflicts */
        .calc-warehouses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .calc-warehouse-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid #e9ecef;
            border-radius: 16px;
            padding: 20px;
            position: relative;
            transition: all 0.3s ease;
            width: auto;
            overflow: visible;
        }
        .calc-warehouse-card:hover {
            border-color: #488C9A;
            box-shadow: 0 4px 20px rgba(72, 140, 154, 0.15);
        }
        .calc-warehouse-card.active {
            border-color: #488C9A;
            box-shadow: 0 4px 20px rgba(72, 140, 154, 0.2);
        }
        .calc-warehouse-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .calc-warehouse-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .calc-warehouse-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 4px;
            font-size: 1.2rem;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .calc-warehouse-remove:hover {
            opacity: 1;
        }
        .calc-warehouse-name-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .calc-warehouse-name-input:focus {
            outline: none;
            border-color: #488C9A;
        }
        .calc-warehouse-card .calc-warehouse-summary {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
            font-size: 0.85rem;
            color: #6c757d;
        }
        .calc-add-warehouse-card {
            background: #fff;
            border: 2px dashed #ccc;
            border-radius: 16px;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            min-height: 180px;
            width: auto;
        }
        .calc-add-warehouse-card:hover {
            border-color: #488C9A;
            background: #f8f9fa;
        }
        .calc-add-warehouse-card .add-icon {
            width: 56px;
            height: 56px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #6c757d;
            margin-bottom: 12px;
            transition: all 0.3s ease;
        }
        .calc-add-warehouse-card:hover .add-icon {
            background: #488C9A;
            color: #fff;
        }
        .calc-add-warehouse-card span {
            color: #6c757d;
            font-weight: 500;
        }

        /* Fee Table */
        .fee-section {
            margin-bottom: 24px;
        }
        .fee-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .fee-section h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #293E4C;
        }
        .warehouse-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .warehouse-tab {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.2s;
        }
        .warehouse-tab:hover {
            background: #e9ecef;
        }
        .warehouse-tab.active {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            border-color: transparent;
        }
        .fee-table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
        }
        .fee-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }
        .fee-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .fee-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .fee-table tr:last-child td {
            border-bottom: none;
        }
        .fee-table tr:hover {
            background: #f8f9fa;
        }
        .fee-table input, .fee-table select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .fee-table input:focus, .fee-table select:focus {
            outline: none;
            border-color: #488C9A;
        }
        .fee-table .amount-input {
            max-width: 120px;
        }
        .fee-row-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 6px;
            font-size: 1.1rem;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .fee-row-remove:hover {
            opacity: 1;
        }
        .btn-add-fee {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 16px;
            background: #f8f9fa;
            color: #488C9A;
            border: 1px dashed #488C9A;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            margin-top: 12px;
            transition: all 0.2s;
        }
        .btn-add-fee:hover {
            background: #488C9A;
            color: #fff;
            border-style: solid;
        }
        .copy-fees-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 12px 16px;
            background: #e8f4f8;
            border-radius: 8px;
            cursor: pointer;
        }
        .copy-fees-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .copy-fees-checkbox span {
            font-size: 0.9rem;
            color: #293E4C;
        }

        /* Storage Schedule */
        .schedule-mode-toggle {
            display: flex;
            background: #f0f0f0;
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 24px;
            width: fit-content;
        }
        .mode-option {
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            transition: all 0.3s ease;
        }
        .mode-option.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .date-range-container {
            display: flex;
            gap: 24px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .date-field {
            flex: 1;
            min-width: 200px;
        }
        .date-field label {
            display: block;
            font-weight: 500;
            color: #293E4C;
            margin-bottom: 8px;
        }
        .date-field input, .date-field select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
        }
        .schedule-table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-top: 20px;
        }
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        .schedule-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            padding: 14px 16px;
            text-align: center;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .schedule-table th.month-col {
            text-align: left;
            background: linear-gradient(135deg, #293E4C 0%, #3a5a6c 100%);
        }
        .schedule-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            text-align: center;
        }
        .schedule-table td.month-cell {
            text-align: left;
            font-weight: 500;
            color: #293E4C;
            background: #f8f9fa;
        }
        .schedule-table input {
            width: 80px;
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
        }
        .schedule-table input:focus {
            outline: none;
            border-color: #488C9A;
        }
        .schedule-table .totals-row {
            background: #f8f9fa;
            font-weight: 600;
        }
        .schedule-table .totals-row td {
            border-top: 2px solid #e0e0e0;
        }
        .warehouse-schedule-header {
            text-align: center;
            padding: 8px !important;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .warehouse-schedule-header.wh-1 { background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%); }
        .warehouse-schedule-header.wh-2 { background: linear-gradient(135deg, #293E4C 0%, #3a5a6c 100%); }
        .warehouse-schedule-header.wh-3 { background: linear-gradient(135deg, #fbb040 0%, #f5a623 100%); }

        /* Simple Mode */
        .simple-mode-container {
            display: none;
        }
        .simple-mode-container.active {
            display: block;
        }
        .detailed-mode-container {
            display: none;
        }
        .detailed-mode-container.active {
            display: block;
        }
        .simple-totals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        .simple-total-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
        }
        .simple-total-card h4 {
            margin: 0 0 16px 0;
            color: #293E4C;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .simple-total-card .total-inputs {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .simple-total-card label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .simple-total-card input {
            width: 100px;
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            text-align: right;
        }

        /* Action Buttons */
        .section-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        .btn-continue {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-continue:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-back:hover {
            background: #e9ecef;
            color: #293E4C;
        }
        .btn-calculate {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 16px 36px;
            background: linear-gradient(135deg, #28a745 0%, #20923c 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-calculate:hover {
            background: linear-gradient(135deg, #20923c 0%, #1a7a32 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.35);
        }

        /* Saved Estimates Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #fff;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #293E4C;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #6c757d;
            cursor: pointer;
            padding: 4px;
        }
        .modal-close:hover {
            color: #293E4C;
        }
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(80vh - 140px);
        }
        .estimate-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .estimate-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .estimate-item:hover {
            border-color: #488C9A;
            background: #fff;
        }
        .estimate-info h4 {
            margin: 0 0 4px 0;
            font-size: 1rem;
            color: #293E4C;
        }
        .estimate-info span {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .estimate-actions {
            display: flex;
            gap: 8px;
        }
        .estimate-actions a, .estimate-actions button {
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-view {
            background: #488C9A;
            color: #fff;
            border: none;
        }
        .btn-view:hover {
            background: #3a7a87;
        }
        .btn-edit {
            background: #f8f9fa;
            color: #488C9A;
            border: 1px solid #488C9A;
        }
        .btn-edit:hover {
            background: #488C9A;
            color: #fff;
        }
        .btn-delete {
            background: #fff;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        .btn-delete:hover {
            background: #dc3545;
            color: #fff;
        }
        .no-estimates {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        .no-estimates-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .calculator-header {
                padding: 24px;
            }
            .calculator-header h1 {
                font-size: 1.75rem;
            }
            .step-indicator {
                flex-wrap: wrap;
                gap: 10px;
            }
            .step-connector {
                display: none;
            }
            .accordion-content.open {
                padding: 16px;
            }
            .warehouses-grid {
                grid-template-columns: 1fr;
            }
            .date-range-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehouse Cost Calculator']); ?>

    <!-- Modern Page Header -->
    <div class="calculator-header">
        <div class="calculator-header-content">
            <div>
                <h1>Warehouse Cost Calculator</h1>
                <p class="subtitle">Compare storage costs across multiple warehouses</p>
            </div>
            <div class="header-actions">
                <button type="button" class="btn-saved-estimates" onclick="openSavedEstimates()">
                    📁 Saved Estimates (<?php echo count($saved_estimates); ?>)
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['calc_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['calc_message']['type']; ?>">
            <?php echo htmlspecialchars($_SESSION['calc_message']['text']); ?>
        </div>
        <?php unset($_SESSION['calc_message']); ?>
    <?php endif; ?>

    <!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step active" data-step="1" onclick="goToStep(1)">
            <div class="step-number">1</div>
            <span class="step-label">Warehouses</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="2" onclick="goToStep(2)">
            <div class="step-number">2</div>
            <span class="step-label">Fees</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="3" onclick="goToStep(3)">
            <div class="step-number">3</div>
            <span class="step-label">Schedule</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="4" onclick="goToStep(4)">
            <div class="step-number">4</div>
            <span class="step-label">Calculate</span>
        </div>
    </div>

    <form method="POST" action="calculator_results.php" id="calculatorForm">
        <!-- Hidden field for editing -->
        <?php if ($editing_estimate): ?>
            <input type="hidden" name="edit_id" value="<?php echo $editing_estimate['id']; ?>">
            <input type="hidden" name="edit_name" value="<?php echo htmlspecialchars($editing_estimate['name']); ?>">
        <?php endif; ?>

        <!-- Step 1: Warehouse Setup -->
        <div class="accordion-section" data-section="1">
            <div class="accordion-header active" onclick="toggleAccordion(1)">
                <h2><span class="step-badge">1</span> Warehouse Setup</h2>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content open">
                <p style="color: #6c757d; margin-bottom: 20px;">Add the warehouses you want to compare. Give each a name to easily identify them in results.</p>

                <div class="calc-warehouses-grid" id="warehousesGrid">
                    <!-- Warehouse cards will be added here by JavaScript -->
                </div>

                <div class="section-actions">
                    <div></div>
                    <button type="button" class="btn-continue" onclick="goToStep(2)">
                        Continue to Fees <span>→</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Fee Configuration -->
        <div class="accordion-section" data-section="2">
            <div class="accordion-header" onclick="toggleAccordion(2)">
                <h2><span class="step-badge">2</span> Fee Configuration</h2>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <p style="color: #6c757d; margin-bottom: 20px;">Configure the fee structure for each warehouse. Common fees include in/out fees and monthly storage costs.</p>

                <div class="warehouse-tabs" id="feeWarehouseTabs">
                    <!-- Tabs generated by JavaScript -->
                </div>

                <div id="feeTabsContent">
                    <!-- Fee tables for each warehouse generated by JavaScript -->
                </div>

                <label class="copy-fees-checkbox">
                    <input type="checkbox" id="copyFeesToAll" onchange="copyFeesToAllWarehouses()">
                    <span>Apply these fees to all warehouses</span>
                </label>

                <div class="section-actions">
                    <button type="button" class="btn-back" onclick="goToStep(1)">
                        <span>←</span> Back
                    </button>
                    <button type="button" class="btn-continue" onclick="goToStep(3)">
                        Continue to Schedule <span>→</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 3: Storage Schedule -->
        <div class="accordion-section" data-section="3">
            <div class="accordion-header" onclick="toggleAccordion(3)">
                <h2><span class="step-badge">3</span> Storage Schedule</h2>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <p style="color: #6c757d; margin-bottom: 20px;">Define the storage period and pallet movements for each warehouse.</p>

                <div class="schedule-mode-toggle">
                    <div class="mode-option active" data-mode="simple" onclick="setScheduleMode('simple')">Simple Mode</div>
                    <div class="mode-option" data-mode="detailed" onclick="setScheduleMode('detailed')">Detailed Mode</div>
                </div>

                <div class="date-range-container">
                    <div class="date-field">
                        <label>Storage Start Month</label>
                        <input type="month" id="startMonth" name="start_month" onchange="updateScheduleTable()">
                    </div>
                    <div class="date-field">
                        <label>Storage End Month</label>
                        <input type="month" id="endMonth" name="end_month" onchange="updateScheduleTable()">
                    </div>
                </div>

                <!-- Simple Mode -->
                <div class="simple-mode-container active" id="simpleModeContainer">
                    <p style="color: #6c757d; margin-bottom: 16px; font-size: 0.9rem;">Enter total pallets in and out for each warehouse. Movements will be distributed evenly across the storage period.</p>
                    <div class="simple-totals-grid" id="simpleTotalsGrid">
                        <!-- Generated by JavaScript -->
                    </div>
                </div>

                <!-- Detailed Mode -->
                <div class="detailed-mode-container" id="detailedModeContainer">
                    <p style="color: #6c757d; margin-bottom: 16px; font-size: 0.9rem;">Enter monthly pallet movements for each warehouse. This gives you precise control over when pallets enter and exit storage.</p>
                    <div class="schedule-table-container">
                        <table class="schedule-table" id="scheduleTable">
                            <thead id="scheduleTableHead">
                                <!-- Generated by JavaScript -->
                            </thead>
                            <tbody id="scheduleTableBody">
                                <!-- Generated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="section-actions">
                    <button type="button" class="btn-back" onclick="goToStep(2)">
                        <span>←</span> Back
                    </button>
                    <button type="button" class="btn-continue" onclick="goToStep(4)">
                        Review & Calculate <span>→</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 4: Review & Calculate -->
        <div class="accordion-section" data-section="4">
            <div class="accordion-header" onclick="toggleAccordion(4)">
                <h2><span class="step-badge">4</span> Review & Calculate</h2>
                <span class="accordion-toggle">▼</span>
            </div>
            <div class="accordion-content">
                <p style="color: #6c757d; margin-bottom: 20px;">Review your configuration and calculate the estimated costs.</p>

                <div id="reviewSummary">
                    <!-- Summary will be generated by JavaScript -->
                </div>

                <div class="section-actions" style="justify-content: center;">
                    <button type="submit" class="btn-calculate">
                        📊 Calculate Costs
                    </button>
                </div>
            </div>
        </div>

        <!-- Hidden form data container -->
        <div id="formDataContainer" style="display: none;">
            <!-- Dynamic form inputs will be added here by JavaScript before submit -->
        </div>
    </form>
</main>

<!-- Saved Estimates Modal -->
<div class="modal-overlay" id="savedEstimatesModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📁 Saved Estimates</h3>
            <button type="button" class="modal-close" onclick="closeSavedEstimates()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($saved_estimates)): ?>
                <div class="estimate-list">
                    <?php foreach ($saved_estimates as $estimate): ?>
                        <div class="estimate-item">
                            <div class="estimate-info">
                                <h4><?php echo htmlspecialchars($estimate['name']); ?></h4>
                                <span>Created: <?php echo date('M j, Y', strtotime($estimate['created_at'])); ?></span>
                            </div>
                            <div class="estimate-actions">
                                <a href="view_estimate.php?id=<?php echo $estimate['id']; ?>" class="btn-view">View</a>
                                <a href="cost_estimate_calculator.php?edit_id=<?php echo $estimate['id']; ?>" class="btn-edit">Edit</a>
                                <button type="button" class="btn-delete" onclick="deleteEstimate(<?php echo $estimate['id']; ?>)">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-estimates">
                    <div class="no-estimates-icon">📭</div>
                    <p>You haven't saved any estimates yet.</p>
                    <p style="font-size: 0.85rem;">Complete a calculation to save your first estimate.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// =====================================================
// Data Structure
// =====================================================
let calculatorData = {
    warehouses: [],
    schedule: {
        mode: 'simple',
        startMonth: '',
        endMonth: '',
        months: []
    }
};

// Load editing data if available
<?php if ($editing_estimate): ?>
const editingData = <?php echo json_encode($editing_estimate); ?>;
<?php else: ?>
const editingData = null;
<?php endif; ?>

// =====================================================
// Initialization
// =====================================================
document.addEventListener('DOMContentLoaded', function() {
    // Set default dates (current month to 6 months from now)
    const now = new Date();
    const startMonth = now.toISOString().slice(0, 7);
    now.setMonth(now.getMonth() + 5);
    const endMonth = now.toISOString().slice(0, 7);

    document.getElementById('startMonth').value = startMonth;
    document.getElementById('endMonth').value = endMonth;

    if (editingData && editingData.warehouses) {
        // Load existing data
        calculatorData = {
            warehouses: editingData.warehouses,
            schedule: editingData.schedule || {
                mode: 'simple',
                startMonth: startMonth,
                endMonth: endMonth,
                months: []
            }
        };

        if (editingData.schedule) {
            document.getElementById('startMonth').value = editingData.schedule.startMonth || startMonth;
            document.getElementById('endMonth').value = editingData.schedule.endMonth || endMonth;
            setScheduleMode(editingData.schedule.mode || 'simple');
        }
    } else {
        // Add first warehouse by default
        addWarehouse();
    }

    renderWarehouses();
    renderFeeTabs();
    updateScheduleTable();
    updateReviewSummary();
});

// =====================================================
// Warehouse Management
// =====================================================
function addWarehouse() {
    const id = Date.now();
    const num = calculatorData.warehouses.length + 1;

    calculatorData.warehouses.push({
        id: id,
        name: `Warehouse ${num}`,
        fees: [
            { name: 'In Fee', amount: 0, unit: 'per_pallet', trigger: 'on_entry' },
            { name: 'Out Fee', amount: 0, unit: 'per_pallet', trigger: 'on_exit' },
            { name: 'Monthly Storage', amount: 0, unit: 'per_pallet', trigger: 'monthly' }
        ],
        currentInventory: 0,
        movements: {},
        simpleTotals: { in: 0, out: 0 }
    });

    renderWarehouses();
    renderFeeTabs();
    updateScheduleTable();
    updateReviewSummary();
}

function removeWarehouse(id) {
    if (calculatorData.warehouses.length <= 1) {
        alert('You must have at least one warehouse.');
        return;
    }

    if (confirm('Remove this warehouse?')) {
        calculatorData.warehouses = calculatorData.warehouses.filter(w => w.id !== id);
        renderWarehouses();
        renderFeeTabs();
        updateScheduleTable();
        updateReviewSummary();
    }
}

function updateWarehouseName(id, name) {
    const wh = calculatorData.warehouses.find(w => w.id === id);
    if (wh) {
        wh.name = name;
        renderFeeTabs();
        updateScheduleTable();
        updateReviewSummary();
    }
}

function renderWarehouses() {
    const grid = document.getElementById('warehousesGrid');

    let html = '';
    calculatorData.warehouses.forEach((wh, index) => {
        const feeCount = wh.fees.length;
        html += `
            <div class="calc-warehouse-card" data-id="${wh.id}">
                <div class="calc-warehouse-card-header">
                    <div class="calc-warehouse-icon">🏭</div>
                    ${calculatorData.warehouses.length > 1 ? `<button type="button" class="calc-warehouse-remove" onclick="removeWarehouse(${wh.id})">✕</button>` : ''}
                </div>
                <input type="text" class="calc-warehouse-name-input" value="${escapeHtml(wh.name)}"
                       onchange="updateWarehouseName(${wh.id}, this.value)" placeholder="Warehouse name">
                <div class="calc-warehouse-summary">
                    ${feeCount} fee${feeCount !== 1 ? 's' : ''} configured
                </div>
            </div>
        `;
    });

    // Add warehouse card (max 5)
    if (calculatorData.warehouses.length < 5) {
        html += `
            <div class="calc-add-warehouse-card" onclick="addWarehouse()">
                <div class="add-icon">+</div>
                <span>Add Warehouse</span>
            </div>
        `;
    }

    grid.innerHTML = html;
}

// =====================================================
// Fee Management
// =====================================================
let activeFeeTab = 0;

function renderFeeTabs() {
    const tabsContainer = document.getElementById('feeWarehouseTabs');
    const contentContainer = document.getElementById('feeTabsContent');

    // Render tabs
    let tabsHtml = '';
    calculatorData.warehouses.forEach((wh, index) => {
        tabsHtml += `
            <div class="warehouse-tab ${index === activeFeeTab ? 'active' : ''}" onclick="switchFeeTab(${index})">
                ${escapeHtml(wh.name)}
            </div>
        `;
    });
    tabsContainer.innerHTML = tabsHtml;

    // Render content for active tab
    if (calculatorData.warehouses.length > 0) {
        const wh = calculatorData.warehouses[activeFeeTab];
        contentContainer.innerHTML = renderFeeTable(wh, activeFeeTab);
    }
}

function switchFeeTab(index) {
    activeFeeTab = index;
    renderFeeTabs();
}

function renderFeeTable(warehouse, whIndex) {
    let rowsHtml = '';
    warehouse.fees.forEach((fee, feeIndex) => {
        // Determine if we need to show extra parameter fields
        const showPalletsPerTruck = fee.unit === 'per_truck';
        const showSqftPerPallet = fee.unit === 'per_sqft';

        rowsHtml += `
            <tr>
                <td>
                    <input type="text" value="${escapeHtml(fee.name)}"
                           onchange="updateFee(${whIndex}, ${feeIndex}, 'name', this.value)">
                </td>
                <td>
                    <input type="number" step="0.01" min="0" class="amount-input" value="${fee.amount}"
                           onchange="updateFee(${whIndex}, ${feeIndex}, 'amount', parseFloat(this.value) || 0)">
                </td>
                <td>
                    <select onchange="updateFeeUnit(${whIndex}, ${feeIndex}, this.value)">
                        <option value="per_pallet" ${fee.unit === 'per_pallet' ? 'selected' : ''}>Per Pallet</option>
                        <option value="per_truck" ${fee.unit === 'per_truck' ? 'selected' : ''}>Per Truck</option>
                        <option value="per_sqft" ${fee.unit === 'per_sqft' ? 'selected' : ''}>Per Sq. Ft.</option>
                        <option value="flat" ${fee.unit === 'flat' ? 'selected' : ''}>Flat Rate</option>
                    </select>
                    ${showPalletsPerTruck ? `
                        <div class="unit-param" style="margin-top: 6px;">
                            <label style="font-size: 0.75rem; color: #6c757d;">Pallets/Truck:</label>
                            <input type="number" min="1" value="${fee.palletsPerTruck || 26}"
                                   style="width: 60px; padding: 4px 6px; font-size: 0.85rem;"
                                   onchange="updateFee(${whIndex}, ${feeIndex}, 'palletsPerTruck', parseInt(this.value) || 26)">
                        </div>
                    ` : ''}
                    ${showSqftPerPallet ? `
                        <div class="unit-param" style="margin-top: 6px;">
                            <label style="font-size: 0.75rem; color: #6c757d;">Sq.ft./Pallet:</label>
                            <input type="number" step="0.1" min="0.1" value="${fee.sqftPerPallet || 13.33}"
                                   style="width: 60px; padding: 4px 6px; font-size: 0.85rem;"
                                   onchange="updateFee(${whIndex}, ${feeIndex}, 'sqftPerPallet', parseFloat(this.value) || 13.33)">
                        </div>
                    ` : ''}
                </td>
                <td>
                    <select onchange="updateFee(${whIndex}, ${feeIndex}, 'trigger', this.value)">
                        <option value="on_entry" ${fee.trigger === 'on_entry' ? 'selected' : ''}>On Entry</option>
                        <option value="on_exit" ${fee.trigger === 'on_exit' ? 'selected' : ''}>On Exit</option>
                        <option value="monthly" ${fee.trigger === 'monthly' ? 'selected' : ''}>Monthly</option>
                        <option value="one_time" ${fee.trigger === 'one_time' ? 'selected' : ''}>One-Time</option>
                    </select>
                </td>
                <td>
                    ${warehouse.fees.length > 1 ? `<button type="button" class="fee-row-remove" onclick="removeFee(${whIndex}, ${feeIndex})">✕</button>` : ''}
                </td>
            </tr>
        `;
    });

    return `
        <div class="fee-section">
            <div class="fee-table-container">
                <table class="fee-table">
                    <thead>
                        <tr>
                            <th>Fee Name</th>
                            <th>Amount ($)</th>
                            <th>Billing Unit</th>
                            <th>When Charged</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn-add-fee" onclick="addFee(${whIndex})">
                + Add Fee
            </button>
        </div>
    `;
}

function updateFeeUnit(whIndex, feeIndex, value) {
    const fee = calculatorData.warehouses[whIndex].fees[feeIndex];
    fee.unit = value;

    // Set default values for new unit types
    if (value === 'per_truck' && !fee.palletsPerTruck) {
        fee.palletsPerTruck = 26; // Default pallets per truck
    }
    if (value === 'per_sqft' && !fee.sqftPerPallet) {
        fee.sqftPerPallet = 13.33; // Default sqft per pallet (40"x48" = 13.33 sqft)
    }

    renderFeeTabs();
    updateReviewSummary();
}

function updateFee(whIndex, feeIndex, field, value) {
    calculatorData.warehouses[whIndex].fees[feeIndex][field] = value;
    updateReviewSummary();
}

function addFee(whIndex) {
    calculatorData.warehouses[whIndex].fees.push({
        name: 'New Fee',
        amount: 0,
        unit: 'per_pallet',
        trigger: 'on_entry'
    });
    renderFeeTabs();
}

function removeFee(whIndex, feeIndex) {
    calculatorData.warehouses[whIndex].fees.splice(feeIndex, 1);
    renderFeeTabs();
}

function copyFeesToAllWarehouses() {
    const checkbox = document.getElementById('copyFeesToAll');
    if (checkbox.checked && calculatorData.warehouses.length > 1) {
        const sourceFees = JSON.parse(JSON.stringify(calculatorData.warehouses[activeFeeTab].fees));

        calculatorData.warehouses.forEach((wh, index) => {
            if (index !== activeFeeTab) {
                wh.fees = JSON.parse(JSON.stringify(sourceFees));
            }
        });

        alert('Fees copied to all warehouses.');
        updateReviewSummary();
    }
}

// =====================================================
// Schedule Management
// =====================================================
function setScheduleMode(mode) {
    calculatorData.schedule.mode = mode;

    document.querySelectorAll('.mode-option').forEach(el => {
        el.classList.toggle('active', el.dataset.mode === mode);
    });

    document.getElementById('simpleModeContainer').classList.toggle('active', mode === 'simple');
    document.getElementById('detailedModeContainer').classList.toggle('active', mode === 'detailed');

    updateScheduleTable();
}

function getMonthsBetween(start, end) {
    const months = [];
    // Parse year and month separately to avoid timezone issues
    const [startYear, startMonth] = start.split('-').map(Number);
    const [endYear, endMonth] = end.split('-').map(Number);

    let currentYear = startYear;
    let currentMonth = startMonth;

    while (currentYear < endYear || (currentYear === endYear && currentMonth <= endMonth)) {
        // Format as YYYY-MM
        const monthStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}`;
        months.push(monthStr);

        // Increment month
        currentMonth++;
        if (currentMonth > 12) {
            currentMonth = 1;
            currentYear++;
        }
    }

    return months;
}

function formatMonth(monthStr) {
    // Parse manually to avoid timezone issues
    const [year, month] = monthStr.split('-').map(Number);
    const date = new Date(year, month - 1, 1); // month is 0-indexed in Date constructor
    return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

function updateScheduleTable() {
    const startMonth = document.getElementById('startMonth').value;
    const endMonth = document.getElementById('endMonth').value;

    if (!startMonth || !endMonth) return;

    calculatorData.schedule.startMonth = startMonth;
    calculatorData.schedule.endMonth = endMonth;
    calculatorData.schedule.months = getMonthsBetween(startMonth, endMonth);

    renderSimpleMode();
    renderDetailedMode();
    updateReviewSummary();
}

function renderSimpleMode() {
    const container = document.getElementById('simpleTotalsGrid');

    let html = '';
    calculatorData.warehouses.forEach((wh, index) => {
        html += `
            <div class="simple-total-card">
                <h4>🏭 ${escapeHtml(wh.name)}</h4>
                <div class="total-inputs">
                    <label>
                        <span>Total Pallets In:</span>
                        <input type="number" min="0" value="${wh.simpleTotals?.in || 0}"
                               onchange="updateSimpleTotal(${index}, 'in', parseInt(this.value) || 0)">
                    </label>
                    <label>
                        <span>Total Pallets Out:</span>
                        <input type="number" min="0" value="${wh.simpleTotals?.out || 0}"
                               onchange="updateSimpleTotal(${index}, 'out', parseInt(this.value) || 0)">
                    </label>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

function updateSimpleTotal(whIndex, field, value) {
    if (!calculatorData.warehouses[whIndex].simpleTotals) {
        calculatorData.warehouses[whIndex].simpleTotals = { in: 0, out: 0 };
    }
    calculatorData.warehouses[whIndex].simpleTotals[field] = value;
    updateReviewSummary();
}

function renderDetailedMode() {
    const thead = document.getElementById('scheduleTableHead');
    const tbody = document.getElementById('scheduleTableBody');
    const months = calculatorData.schedule.months;

    if (months.length === 0) {
        thead.innerHTML = '<tr><th>Select date range above</th></tr>';
        tbody.innerHTML = '';
        return;
    }

    // Header row
    let headerHtml = '<tr><th class="month-col">Month</th>';
    calculatorData.warehouses.forEach((wh, index) => {
        const colorClass = `wh-${(index % 3) + 1}`;
        headerHtml += `
            <th colspan="2" class="warehouse-schedule-header ${colorClass}">${escapeHtml(wh.name)}</th>
        `;
    });
    headerHtml += '</tr>';

    // Sub-header row
    headerHtml += '<tr><th class="month-col"></th>';
    calculatorData.warehouses.forEach(() => {
        headerHtml += '<th>In</th><th>Out</th>';
    });
    headerHtml += '</tr>';
    thead.innerHTML = headerHtml;

    // Body rows
    let bodyHtml = '';
    months.forEach(month => {
        bodyHtml += `<tr><td class="month-cell">${formatMonth(month)}</td>`;
        calculatorData.warehouses.forEach((wh, whIndex) => {
            const inVal = wh.movements?.[month]?.in || 0;
            const outVal = wh.movements?.[month]?.out || 0;
            bodyHtml += `
                <td>
                    <input type="number" min="0" value="${inVal}"
                           onchange="updateMovement(${whIndex}, '${month}', 'in', parseInt(this.value) || 0)">
                </td>
                <td>
                    <input type="number" min="0" value="${outVal}"
                           onchange="updateMovement(${whIndex}, '${month}', 'out', parseInt(this.value) || 0)">
                </td>
            `;
        });
        bodyHtml += '</tr>';
    });

    // Totals row
    bodyHtml += '<tr class="totals-row"><td class="month-cell"><strong>Totals</strong></td>';
    calculatorData.warehouses.forEach((wh, whIndex) => {
        let totalIn = 0, totalOut = 0;
        months.forEach(month => {
            totalIn += wh.movements?.[month]?.in || 0;
            totalOut += wh.movements?.[month]?.out || 0;
        });
        bodyHtml += `<td><strong>${totalIn}</strong></td><td><strong>${totalOut}</strong></td>`;
    });
    bodyHtml += '</tr>';

    tbody.innerHTML = bodyHtml;
}

function updateMovement(whIndex, month, field, value) {
    if (!calculatorData.warehouses[whIndex].movements) {
        calculatorData.warehouses[whIndex].movements = {};
    }
    if (!calculatorData.warehouses[whIndex].movements[month]) {
        calculatorData.warehouses[whIndex].movements[month] = { in: 0, out: 0 };
    }
    calculatorData.warehouses[whIndex].movements[month][field] = value;

    // Update totals row
    renderDetailedMode();
    updateReviewSummary();
}

// =====================================================
// Review Summary
// =====================================================
function updateReviewSummary() {
    const container = document.getElementById('reviewSummary');
    const months = calculatorData.schedule.months;

    let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">';

    calculatorData.warehouses.forEach((wh, index) => {
        // Calculate totals based on mode
        let totalIn = 0, totalOut = 0;
        if (calculatorData.schedule.mode === 'simple') {
            totalIn = wh.simpleTotals?.in || 0;
            totalOut = wh.simpleTotals?.out || 0;
        } else {
            months.forEach(month => {
                totalIn += wh.movements?.[month]?.in || 0;
                totalOut += wh.movements?.[month]?.out || 0;
            });
        }

        const feeSummary = wh.fees.map(f => `${f.name}: $${f.amount.toFixed(2)}/${f.unit.replace('_', ' ')}`).join('<br>');

        html += `
            <div style="background: #f8f9fa; border-radius: 12px; padding: 20px; border: 1px solid #e9ecef;">
                <h4 style="margin: 0 0 16px 0; color: #293E4C; display: flex; align-items: center; gap: 8px;">
                    🏭 ${escapeHtml(wh.name)}
                </h4>
                <div style="font-size: 0.9rem; color: #6c757d;">
                    <p><strong>Fees:</strong><br>${feeSummary || 'No fees configured'}</p>
                    <p><strong>Storage Period:</strong> ${months.length} months</p>
                    <p><strong>Total In:</strong> ${totalIn} pallets</p>
                    <p><strong>Total Out:</strong> ${totalOut} pallets</p>
                </div>
            </div>
        `;
    });

    html += '</div>';
    container.innerHTML = html;
}

// =====================================================
// Accordion & Navigation
// =====================================================
function toggleAccordion(section) {
    const sectionEl = document.querySelector(`.accordion-section[data-section="${section}"]`);
    const header = sectionEl.querySelector('.accordion-header');
    const content = sectionEl.querySelector('.accordion-content');

    header.classList.toggle('active');
    content.classList.toggle('open');
}

function goToStep(step) {
    // Update step indicator
    document.querySelectorAll('.step').forEach((el, index) => {
        const stepNum = index + 1;
        el.classList.remove('active', 'completed');
        if (stepNum < step) {
            el.classList.add('completed');
        } else if (stepNum === step) {
            el.classList.add('active');
        }
    });

    document.querySelectorAll('.step-connector').forEach((el, index) => {
        el.classList.toggle('completed', index < step - 1);
    });

    // Close all accordions and open the target
    document.querySelectorAll('.accordion-section').forEach(section => {
        const sectionNum = parseInt(section.dataset.section);
        const header = section.querySelector('.accordion-header');
        const content = section.querySelector('.accordion-content');

        if (sectionNum === step) {
            header.classList.add('active');
            content.classList.add('open');
            // Scroll to section
            setTimeout(() => {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        } else {
            header.classList.remove('active');
            content.classList.remove('open');
        }
    });

    // Update content for the step
    if (step === 2) {
        renderFeeTabs();
    } else if (step === 3) {
        updateScheduleTable();
    } else if (step === 4) {
        updateReviewSummary();
    }
}

// =====================================================
// Modal Functions
// =====================================================
function openSavedEstimates() {
    document.getElementById('savedEstimatesModal').classList.add('active');
}

function closeSavedEstimates() {
    document.getElementById('savedEstimatesModal').classList.remove('active');
}

function deleteEstimate(id) {
    if (confirm('Are you sure you want to delete this estimate?')) {
        window.location.href = `cost_estimate_calculator.php?delete_estimate=${id}`;
    }
}

// Close modal on overlay click
document.getElementById('savedEstimatesModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSavedEstimates();
    }
});

// =====================================================
// Form Submission
// =====================================================
document.getElementById('calculatorForm').addEventListener('submit', function(e) {
    // Validate data
    if (calculatorData.warehouses.length === 0) {
        e.preventDefault();
        alert('Please add at least one warehouse.');
        goToStep(1);
        return;
    }

    // Check if any fees are configured
    let hasValidFees = false;
    calculatorData.warehouses.forEach(wh => {
        wh.fees.forEach(fee => {
            if (fee.amount > 0) hasValidFees = true;
        });
    });

    if (!hasValidFees) {
        if (!confirm('No fees have been configured. Continue anyway?')) {
            e.preventDefault();
            goToStep(2);
            return;
        }
    }

    // Prepare form data
    const formDataContainer = document.getElementById('formDataContainer');
    formDataContainer.innerHTML = '';

    // Add calculator data as JSON
    const dataInput = document.createElement('input');
    dataInput.type = 'hidden';
    dataInput.name = 'calculator_data';
    dataInput.value = JSON.stringify(calculatorData);
    formDataContainer.appendChild(dataInput);
});

// =====================================================
// Utility Functions
// =====================================================
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>
