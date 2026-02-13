<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'global_admin' && $role !== 'admin' && $role !== 'customer_admin') {
    header("Location: unauthorized");
    exit();
}

$estimate_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($estimate_id <= 0) {
    die("Estimate ID not specified.");
}

require_once '../config.php';
require_once 'notification_helpers.php';
require_once 'document_helpers.php';

// Notification toggles
$notify_user_on_rate_update = true;
$notify_account_admins_on_rate_update = false;
$notify_global_admins_on_rate_update = false;

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$currentUserId = (int)$_SESSION['user_id'];
$adminAccounts = in_array($role, ['admin', 'customer_admin'], true) ? account_ids_for_user($currentUserId) : [];

function slp_fetch_warehouses_with_costs($conn) {
    $warehouses = [];
    $sql = "SELECT
                w.id, w.name, w.street_address, w.city, w.state, w.zip_code, w.country, w.image_url,
                MAX(CASE WHEN wci.trigger_event = 'entry' AND wci.is_active = 1 THEN wci.amount END) AS in_fee,
                MAX(CASE WHEN wci.trigger_event = 'exit' AND wci.is_active = 1 THEN wci.amount END) AS out_fee,
                MAX(CASE WHEN wci.trigger_event = 'monthly' AND wci.is_active = 1 THEN wci.amount END) AS monthly_storage_fee
            FROM warehouses w
            LEFT JOIN warehouse_cost_items wci ON w.id = wci.warehouse_id AND wci.is_active = 1
            GROUP BY w.id, w.name, w.street_address, w.city, w.state, w.zip_code, w.country, w.image_url
            ORDER BY w.name ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['in_fee'] = isset($row['in_fee']) ? (float)$row['in_fee'] : 0;
            $row['out_fee'] = isset($row['out_fee']) ? (float)$row['out_fee'] : 0;
            $row['monthly_storage_fee'] = isset($row['monthly_storage_fee']) ? (float)$row['monthly_storage_fee'] : 0;
            $warehouses[] = $row;
        }
        $stmt->close();
    }

    return $warehouses;
}

function slp_format_warehouse_location($warehouse) {
    $city = trim($warehouse['city'] ?? '');
    $state = trim($warehouse['state'] ?? '');
    $street = trim($warehouse['street_address'] ?? '');

    $cityState = trim($city . ($state !== '' ? ', ' . $state : ''));
    if ($cityState !== '') {
        return $cityState;
    }

    $streetCity = trim($street . ($city !== '' ? ', ' . $city : ''));
    if ($streetCity !== '') {
        return $streetCity;
    }

    return $warehouse['name'] ?? 'Warehouse';
}

// Fetch estimate data with account info
$stmt = $conn->prepare("
    SELECT 
        wq.user_id,
        wq.name,
        wq.estimate_data,
        wq.created_at,
        cau.account_id,
        ca.name AS account_name,
        u.username
    FROM warehouse_quotes wq
    LEFT JOIN (
        SELECT user_id, MIN(account_id) AS account_id
        FROM customer_account_users
        GROUP BY user_id
    ) AS cau ON wq.user_id = cau.user_id
    LEFT JOIN customer_accounts ca ON ca.id = cau.account_id
    LEFT JOIN users u ON u.id = wq.user_id
    WHERE wq.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $estimate_id);
$stmt->execute();
$result = $stmt->get_result();
$estimateRow = $result->fetch_assoc();
$stmt->close();

if (!$estimateRow) {
    $conn->close();
    die("Estimate not found.");
}

$estimate_data = json_decode($estimateRow['estimate_data'], true) ?? [];

// Admin scoping
if (in_array($role, ['admin', 'customer_admin'], true)) {
    $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
    if (empty(array_intersect($adminAccounts, $ownerAccounts))) {
        $conn->close();
        header("Location: unauthorized");
        exit();
    }
}

$warehouses = slp_fetch_warehouses_with_costs($conn);
$warehousesById = [];
foreach ($warehouses as $wh) {
    $warehousesById[$wh['id']] = $wh;
}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_quote'])) {
        $warehouse_id = intval($_POST['existing_warehouse_id'] ?? 0);
        $warehouse_location = trim($_POST['warehouse_location'] ?? '');

        $in_fee_raw = $_POST['in_fee'] ?? '';
        $out_fee_raw = $_POST['out_fee'] ?? '';
        $monthly_fee_raw = $_POST['monthly_storage_fee'] ?? '';

        $in_fee = ($in_fee_raw === '') ? 0 : floatval($in_fee_raw);
        $out_fee = ($out_fee_raw === '') ? 0 : floatval($out_fee_raw);
        $monthly_storage_fee = ($monthly_fee_raw === '') ? 0 : floatval($monthly_fee_raw);

        $selectedWarehouse = $warehousesById[$warehouse_id] ?? null;
        if (!$selectedWarehouse) {
            $error_message = "Please select an existing warehouse to use for this quote.";
        } else {
            if ($warehouse_location === '') {
                $warehouse_location = slp_format_warehouse_location($selectedWarehouse);
            }
            if ($in_fee_raw === '' && $selectedWarehouse['in_fee'] > 0) {
                $in_fee = (float)$selectedWarehouse['in_fee'];
            }
            if ($out_fee_raw === '' && $selectedWarehouse['out_fee'] > 0) {
                $out_fee = (float)$selectedWarehouse['out_fee'];
            }
            if ($monthly_fee_raw === '' && $selectedWarehouse['monthly_storage_fee'] > 0) {
                $monthly_storage_fee = (float)$selectedWarehouse['monthly_storage_fee'];
            }
        }

        if (empty($error_message) && (empty($warehouse_location) || $warehouse_id <= 0 || $in_fee < 0 || $out_fee < 0 || $monthly_storage_fee < 0)) {
            $error_message = "Please fill in all required fields with valid values.";
        }

        // Handle multiple document uploads
        $uploaded_doc_ids = [];
        if (empty($error_message)) {
            if (!empty($_FILES['quote_documents']['name'][0])) {
                $file_count = count($_FILES['quote_documents']['name']);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($_FILES['quote_documents']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_data = [
                            'name' => $_FILES['quote_documents']['name'][$i],
                            'type' => $_FILES['quote_documents']['type'][$i],
                            'tmp_name' => $_FILES['quote_documents']['tmp_name'][$i],
                            'error' => $_FILES['quote_documents']['error'][$i],
                            'size' => $_FILES['quote_documents']['size'][$i]
                        ];

                        try {
                            $processed_file = processDocumentUpload($file_data, 'warehousing');
                            $document_data = [
                                'project_id' => null,
                                'warehouse_id' => $warehouse_id > 0 ? $warehouse_id : null,
                                'document_type' => 'warehousing',
                                'document_sub_type' => 'Quote',
                                'original_name' => $processed_file['original_name'],
                                'file_size' => $processed_file['size'],
                                'mime_type' => $processed_file['mime_type'],
                                'uploaded_by' => $currentUserId,
                                'tmp_name' => $processed_file['tmp_name'],
                                'description' => "Warehouse quote for {$warehouse_location}",
                                'entity_context' => json_encode(['warehouse_quote_id' => $estimate_id, 'warehouse_location' => $warehouse_location])
                            ];

                            $save_result = saveDocumentToProjectDocuments($conn, $document_data);
                            if ($save_result && isset($save_result['document_id'])) {
                                $uploaded_doc_ids[] = $save_result['document_id'];
                            }
                        } catch (Exception $e) {
                            error_log("Failed to upload document: " . $e->getMessage());
                        }
                    }
                }
            }

            if (empty($uploaded_doc_ids)) {
                $error_message = "Please attach at least one document for this quote.";
            }
        }

        if (empty($error_message)) {
            $new_quote = [
                'warehouse_location' => $warehouse_location,
                'warehouse_id' => $warehouse_id,
                'in_fee_per_pallet' => $in_fee,
                'out_fee_per_pallet' => $out_fee,
                'monthly_storage_cost_per_pallet' => $monthly_storage_fee,
                'document_ids' => $uploaded_doc_ids
            ];
            if (!isset($estimate_data['quotes'])) {
                $estimate_data['quotes'] = [];
            }
            $estimate_data['quotes'][] = $new_quote;

            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote added successfully with " . count($uploaded_doc_ids) . " document(s)!";

                $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
                $title = "Warehouse rate added: " . ($estimateRow['name'] ?? 'Estimate');
                $message = "Rates were added by " . ($_SESSION['username'] ?? 'an admin') . " for " . ($estimate_data['project_location'] ?? 'location') . ".";
                $link = 'view_warehouse_estimate?id=' . $estimate_id;

                if ($notify_user_on_rate_update) {
                    notify_user((int)$estimateRow['user_id'], 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_account_admins_on_rate_update) {
                    notify_account_admins($ownerAccounts, 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_global_admins_on_rate_update) {
                    notify_global_admins('warehouse_estimate_rated', $title, $message, $link);
                }
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        }
    } elseif (isset($_POST['delete_quote'])) {
        $quote_index = intval($_POST['quote_index'] ?? -1);
        if (isset($estimate_data['quotes'][$quote_index])) {
            array_splice($estimate_data['quotes'], $quote_index, 1);
            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote deleted successfully!";
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        } else {
            $error_message = "Quote not found.";
        }
    }
}

// Fetch documents for display
$quote_documents = [];
if (!empty($estimate_data['quotes'])) {
    foreach ($estimate_data['quotes'] as $idx => $quote) {
        if (!empty($quote['document_ids'])) {
            $placeholders = implode(',', array_fill(0, count($quote['document_ids']), '?'));
            $doc_stmt = $conn->prepare("SELECT id, original_file_name, file_size FROM project_documents WHERE id IN ($placeholders) AND is_active = 1");
            $types = str_repeat('i', count($quote['document_ids']));
            $doc_stmt->bind_param($types, ...$quote['document_ids']);
            $doc_stmt->execute();
            $doc_result = $doc_stmt->get_result();
            $quote_documents[$idx] = [];
            while ($doc_row = $doc_result->fetch_assoc()) {
                $quote_documents[$idx][] = $doc_row;
            }
            $doc_stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Warehouse Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        label { display: block; margin-top: 15px; font-weight: 600; color: #0f172a; font-size: 0.95em; }
        input { width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95em; transition: all 0.2s ease; }
        input:focus { outline: none; border-color: #488C9A; box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1); }
        select { width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95em; background: white; cursor: pointer; transition: all 0.2s ease; }
        select:focus { outline: none; border-color: #488C9A; box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1); }
        button { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color: white; padding: 11px 24px; margin: 10px 0; border: none; border-radius: 8px; font-size: 0.95em; cursor: pointer; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25); }
        button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(72, 140, 154, 0.35); background: linear-gradient(135deg, #3A6E7F 0%, #2d5c70 100%); }
        button:active { transform: translateY(0); }
        .success-message { color: #0f5132; background: #d1e7dd; border: 1px solid #badbcc; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        .error-message { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        h1, h2 { margin-top: 20px; }
        .admin-hero { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 24px; margin-bottom: 18px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06); border: 1px solid rgba(72, 140, 154, 0.08); position: relative; overflow: hidden; }
        .admin-hero::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .admin-hero__content { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .rate-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; align-items: flex-start; }
        .card-block { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 24px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); transition: all 0.3s ease; }
        .card-block:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .card-header { margin-bottom: 16px; }
        .card-title { margin: 0; font-size: 1.05em; font-weight: 700; color: #0f172a; }
        .card-sub { margin: 8px 0 0; color: #6b7280; font-size: 0.9em; }
        .eyebrow { text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.75em; font-weight: 700; color: #488C9A; margin: 0 0 8px 0; }
        .required { color: #d97757; font-weight: 700; }
        .warehouse-preview { background: linear-gradient(135deg, #f0f9fb 0%, #f8fafc 100%); border: 1px solid rgba(72, 140, 154, 0.2); padding: 14px; border-radius: 10px; margin-top: 10px; }
        .preview-title { font-weight: 700; color: #0f172a; margin: 0; }
        .preview-meta { color: #6b7280; font-size: 0.9em; margin-top: 6px; }
        .fee-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 16px 0; }
        .fee-grid > div { display: flex; flex-direction: column; }
        .hidden { display: none; }

        .hero-sub { color: #556; margin: 4px 0 0; }

        /* File Upload Zone */
        .file-upload-zone {
            border: 2px dashed #d1d5db;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f9fafb;
        }
        .file-upload-zone:hover {
            border-color: #488C9A;
            background: #f0f9fb;
        }
        .file-upload-zone.dragover {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .selected-files-list {
            margin-top: 16px;
        }
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .file-icon {
            color: #488C9A;
            font-size: 1.2em;
        }
        .remove-file-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.2s ease;
        }
        .remove-file-btn:hover {
            background: #dc2626;
        }

        .docs-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 350px;
        }
        .doc-item-inline {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .doc-item-inline:hover {
            background: #e7f6f8;
            border-color: #488C9A;
        }
        .doc-link {
            color: #293E4C;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9em;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .doc-link:hover {
            color: #488C9A;
        }
        .doc-size {
            font-size: 0.8em;
            color: #6c757d;
            white-space: nowrap;
        }
    </style>
</head>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(getGoogleMapsApiKey()); ?>&libraries=places"></script>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehouse Estimate']); ?>
    <section class="admin-hero">
        <div class="admin-hero__content">
            <div>
                <h1>Warehouse Estimate</h1>
                <p class="hero-sub"><?php echo htmlspecialchars($estimateRow['name'] ?? ''); ?></p>
            </div>
        </div>
    </section>

    <?php
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <ul>
        <li><strong>Customer:</strong> <?php echo htmlspecialchars($estimateRow['account_name'] ?? 'Unassigned'); ?></li>
        <li><strong>Created At:</strong> <?php echo htmlspecialchars($estimateRow['created_at'] ?? ''); ?></li>
    </ul>

    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Project Location:</strong> <?php echo htmlspecialchars($estimate_data['project_location'] ?? ''); ?></li>
        <li><strong>Estimated Storage Start:</strong> <?php echo htmlspecialchars($estimate_data['estimated_storage_start'] ?? ''); ?></li>
        <li><strong>Estimated Number of Pallets:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets'] ?? ''); ?></li>
        <li><strong>Pallet Dimensions:</strong> <?php echo htmlspecialchars($estimate_data['pallet_length'] ?? '') . ' x ' . htmlspecialchars($estimate_data['pallet_width'] ?? '') . ' ' . strtolower($estimate_data['pallet_unit'] ?? 'in'); ?></li>
        <li><strong>Stackable:</strong> <?php echo !empty($estimate_data['stackable']) ? 'Yes' : 'No'; ?></li>
        <li><strong>Calculated Square Feet:</strong> <?php echo number_format($estimate_data['square_feet'] ?? 0, 2); ?> sq ft</li>
    </ul>


<h2>Add Warehouse Rate</h2>
<form method="POST" action="" enctype="multipart/form-data" id="addQuoteForm">
    <input type="hidden" name="add_quote" value="1">

    <div class="rate-grid">
        <div class="card-block">
            <div class="card-header">
                <p class="eyebrow">Warehouse Source</p>
                <h3 class="card-title">Select a warehouse</h3>
            </div>

            <label for="existing_warehouse_id">Choose Warehouse <span class="required">*</span></label>
            <select id="existing_warehouse_id" name="existing_warehouse_id" required>
                <option value="">Select a warehouse...</option>
                <?php foreach ($warehouses as $wh): ?>
                    <option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <div class="warehouse-preview" id="existingWarehouseSummary" style="margin-top: 16px;">
                <div class="preview-title" id="summaryName">Choose a warehouse to preview details.</div>
                <div class="preview-meta" id="summaryAddress"></div>
                <div class="preview-meta" id="summaryFees"></div>
            </div>

            <p style="font-size: 0.9em; color: #6b7280; margin-top: 16px;">
                <i class="fas fa-info-circle" style="color: #488C9A;"></i>
                Need to add a new warehouse? <a href="add_warehouse.php" style="color: #488C9A; font-weight: 600;">Create one here</a>
            </p>
        </div>

        <div class="card-block">
            <div class="card-header">
                <p class="eyebrow">Rates & Documentation</p>
                <h3 class="card-title">Set fees and attach the quote</h3>
            </div>

            <label for="warehouse_location">Location Reference <span class="required">*</span></label>
            <input type="text" id="warehouse_location" name="warehouse_location" placeholder="e.g., Phoenix, AZ" required>
            <p style="font-size: 0.85em; color: #6b7280; margin: 4px 0 12px;">Auto-populated from warehouse, override if needed</p>

            <div class="fee-grid">
                <div>
                    <label for="in_fee">Entry Fee (per pallet) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="in_fee" placeholder="0.00" required>
                </div>
                <div>
                    <label for="out_fee">Exit Fee (per pallet) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="out_fee" placeholder="0.00" required>
                </div>
                <div>
                    <label for="monthly_storage_fee">Monthly Fee (per pallet) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="monthly_storage_fee" placeholder="0.00" required>
                </div>
            </div>

            <label for="quote_documents">Quote Documents <span class="required">*</span></label>
            <div class="file-upload-zone" id="fileUploadZone">
                <i class="fas fa-cloud-upload-alt" style="font-size: 3em; color: #9ca3af; margin-bottom: 16px;"></i>
                <p style="margin: 0 0 8px; font-weight: 600; color: #488C9A;">Drop files here or click to browse</p>
                <p style="margin: 0; font-size: 0.85em; color: #9ca3af;">PDF, DOC, DOCX, JPG, PNG (Max: 50MB each)</p>
            </div>
            <input type="file" id="quote_documents" name="quote_documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple required style="display: none;">
            <div id="selectedFiles" class="selected-files-list"></div>

            <button type="submit" style="margin-top: 20px;">Add Quote</button>
        </div>
    </div>
</form>

<h2>Existing Rates</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>In Fee (per pallet)</th>
                <th>Out Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
                <th>Documents</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $index => $quote): 
                $doc_count = !empty($quote['document_ids']) ? count($quote['document_ids']) : (!empty($quote['document']) ? 1 : 0);
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['in_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['out_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                    <td>
                        <?php if ($doc_count > 0): ?>
                            <div class="docs-cell">
                                <?php 
                                $docs = $quote_documents[$index] ?? [];
                                foreach ($docs as $doc): 
                                    $size = $doc['file_size'];
                                    if ($size < 1024) {
                                        $formatted_size = $size . ' B';
                                    } elseif ($size < 1024 * 1024) {
                                        $formatted_size = round($size / 1024, 1) . ' KB';
                                    } else {
                                        $formatted_size = round($size / (1024 * 1024), 1) . ' MB';
                                    }
                                ?>
                                    <div class="doc-item-inline">
                                        <i class="fas fa-file-alt" style="color: #488C9A;"></i>
                                        <a href="download_document.php?id=<?php echo $doc['id']; ?>" target="_blank" class="doc-link" title="<?php echo htmlspecialchars($doc['original_file_name']); ?>">
                                            <?php echo htmlspecialchars(strlen($doc['original_file_name']) > 25 ? substr($doc['original_file_name'], 0, 25) . '...' : $doc['original_file_name']); ?>
                                        </a>
                                        <span class="doc-size">(<?php echo $formatted_size; ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color: #9ca3af; font-style: italic;">No documents</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this quote?');">
                            <input type="hidden" name="delete_quote" value="1">
                            <input type="hidden" name="quote_index" value="<?php echo $index; ?>">
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No rates added yet.</p>
    <?php endif; ?>
    <script>

document.addEventListener("DOMContentLoaded", function() {
    const warehousesData = <?php echo json_encode($warehouses ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
    const locationInput = document.getElementById("warehouse_location");
    const inFeeInput = document.querySelector('input[name="in_fee"]');
    const outFeeInput = document.querySelector('input[name="out_fee"]');
    const storageInput = document.querySelector('input[name="monthly_storage_fee"]');
    const warehouseSelect = document.getElementById('existing_warehouse_id');
    const summaryName = document.getElementById('summaryName');
    const summaryAddress = document.getElementById('summaryAddress');
    const summaryFees = document.getElementById('summaryFees');

    // Helper function to format warehouse location
    function formatLocation(wh) {
        if (!wh) return '';
        const city = (wh.city || '').trim();
        const state = (wh.state || '').trim();
        if (city || state) {
            return `${city}${city && state ? ', ' : ''}${state}`.trim();
        }
        return (wh.name || '').trim();
    }

    // Update preview summary when warehouse is selected
    function updateSummary(wh) {
        if (!summaryName || !summaryAddress || !summaryFees) return;
        if (!wh) {
            summaryName.textContent = 'Choose a warehouse to preview details.';
            summaryAddress.textContent = '';
            summaryFees.textContent = '';
            return;
        }
        summaryName.textContent = wh.name || 'Warehouse';
        const parts = [];
        if (wh.street_address) parts.push(wh.street_address);
        const loc = formatLocation(wh);
        if (loc) parts.push(loc);
        if (wh.zip_code) parts.push(wh.zip_code);
        if (wh.country) parts.push(wh.country);
        summaryAddress.textContent = parts.join(' · ');

        const fees = [];
        if (wh.in_fee > 0) fees.push(`Entry: $${parseFloat(wh.in_fee).toFixed(2)}`);
        if (wh.out_fee > 0) fees.push(`Exit: $${parseFloat(wh.out_fee).toFixed(2)}`);
        if (wh.monthly_storage_fee > 0) fees.push(`Monthly: $${parseFloat(wh.monthly_storage_fee).toFixed(2)}`);
        summaryFees.textContent = fees.length ? fees.join('  •  ') : 'No default rates on file.';
    }

    // Populate form fields from selected warehouse
    function populateFromWarehouse(id) {
        const wh = warehousesData.find(w => String(w.id) === String(id));
        updateSummary(wh);
        if (!wh) {
            if (locationInput) locationInput.value = '';
            if (inFeeInput) inFeeInput.value = '';
            if (outFeeInput) outFeeInput.value = '';
            if (storageInput) storageInput.value = '';
            return;
        }
        if (locationInput) {
            locationInput.value = formatLocation(wh);
        }
        if (inFeeInput) inFeeInput.value = wh.in_fee !== undefined && wh.in_fee !== null ? wh.in_fee : '';
        if (outFeeInput) outFeeInput.value = wh.out_fee !== undefined && wh.out_fee !== null ? wh.out_fee : '';
        if (storageInput) storageInput.value = wh.monthly_storage_fee !== undefined && wh.monthly_storage_fee !== null ? wh.monthly_storage_fee : '';
    }

    // Update when warehouse selection changes
    if (warehouseSelect) {
        warehouseSelect.addEventListener('change', (e) => {
            populateFromWarehouse(e.target.value);
        });
    }

    // File upload functionality
    const fileUploadZone = document.getElementById('fileUploadZone');
    const fileInput = document.getElementById('quote_documents');
    const selectedFilesList = document.getElementById('selectedFiles');
    let selectedFiles = [];

    fileUploadZone.addEventListener('click', () => fileInput.click());

    fileUploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        fileUploadZone.classList.add('dragover');
    });

    fileUploadZone.addEventListener('dragleave', () => {
        fileUploadZone.classList.remove('dragover');
    });

    fileUploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        fileUploadZone.classList.remove('dragover');
        const files = Array.from(e.dataTransfer.files);
        handleFiles(files);
    });

    fileInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files);
        handleFiles(files);
    });

    function handleFiles(files) {
        selectedFiles = [...selectedFiles, ...files];
        updateFilesList();
    }

    function removeFile(index) {
        selectedFiles.splice(index, 1);
        updateFilesList();
    }

    function updateFilesList() {
        if (selectedFiles.length === 0) {
            selectedFilesList.innerHTML = '';
            fileInput.required = true;
            return;
        }

        fileInput.required = false;

        let html = '';
        selectedFiles.forEach((file, index) => {
            const sizeKB = (file.size / 1024).toFixed(1);
            html += `
                <div class="file-item">
                    <div class="file-info">
                        <i class="fas fa-file file-icon"></i>
                        <div>
                            <div style="font-weight: 600;">${file.name}</div>
                            <div style="font-size: 0.85em; color: #6c757d;">${sizeKB} KB</div>
                        </div>
                    </div>
                    <button type="button" class="remove-file-btn" onclick="removeFileAt(${index})">Remove</button>
                </div>
            `;
        });
        selectedFilesList.innerHTML = html;

        const dataTransfer = new DataTransfer();
        selectedFiles.forEach(file => dataTransfer.items.add(file));
        fileInput.files = dataTransfer.files;
    }

    window.removeFileAt = function(index) {
        removeFile(index);
    };
});
    </script>
</main>
</body>
</html>
