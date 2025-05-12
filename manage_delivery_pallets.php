<?php
session_name("logistics_session");
session_start();

/* ───────────────────────────── CSRF TOKEN ────────────────────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ─────────────────────────── SECURITY / AUTH ─────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    header("Location: unauthorized");
    exit();
}

/* ───────────────────────── PARAMS & CONNECTION ───────────────────────── */
if (empty($_GET['delivery_id']) || empty($_GET['wattage'])) {
    die("Delivery ID or Wattage missing.");
}
$delivery_id = (int)$_GET['delivery_id'];
$required_wattage = $_GET['wattage']; // Keep as string for comparison

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

// Initialize messages
$success_message = $_SESSION['manage_pallets_success'] ?? null;
$error_message = $_SESSION['manage_pallets_error'] ?? null;
unset($_SESSION['manage_pallets_success'], $_SESSION['manage_pallets_error']);

// Fetch the required quantity for the delivery
$required_delivery_quantity = 0;
try {
    $stmt_req_qty = $conn->prepare("SELECT quantity FROM deliveries WHERE id = ?");
    if ($stmt_req_qty) {
        $stmt_req_qty->bind_param("i", $delivery_id);
        $stmt_req_qty->execute();
        $stmt_req_qty->bind_result($req_qty);
        if ($stmt_req_qty->fetch()) {
            $required_delivery_quantity = (int)$req_qty;
        }
        $stmt_req_qty->close();
    } else {
        throw new Exception("Failed to prepare required quantity statement: " . $conn->error);
    }
} catch (Exception $e) {
    $error_message .= " Error fetching required quantity: " . $e->getMessage();
}

/* ───────────────────── FETCH INITIAL & AVAILABLE PALLETS ───────────────────── */
$all_relevant_pallets = [];
$initially_associated_ids = [];

try {
    // Fetch pallets ASSOCIATED WITH THIS DELIVERY + AVAILABLE pallets matching wattage
    $stmt = $conn->prepare("
        SELECT 
            ip.id, 
            ip.pallet_identifier, 
            ip.wattage, 
            ip.quantity, 
            ip.status, 
            w.name as warehouse_name,
            CASE 
                WHEN dp.delivery_id = ? THEN 1 -- Associated with this delivery
                ELSE 0 -- Available
            END as is_associated_with_current,
            EXISTS (
                SELECT 1 
                FROM delivery_pallets dp_any 
                WHERE dp_any.inventory_pallet_id = ip.id 
                  AND dp_any.delivery_id != ?
            ) as is_associated_elsewhere
        FROM inventory_pallets ip
        LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
        LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id AND dp.delivery_id = ?
        WHERE ip.wattage = ? 
          AND (
              dp.delivery_id = ?
              OR 
              (
                ip.status IN ('At Manufacturer', 'In Warehouse') 
                AND NOT EXISTS (
                    SELECT 1 
                    FROM delivery_pallets dp_check 
                    WHERE dp_check.inventory_pallet_id = ip.id
                )
              )
          )
        ORDER BY ip.id
    ");
    
    if (!$stmt) throw new Exception("Prepare statement failed: " . $conn->error);

    // Bind parameters in the correct order
    $stmt->bind_param("iisii", $delivery_id, $delivery_id, $delivery_id, $required_wattage, $delivery_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Keep only pallets associated with this delivery or completely unassociated
        if ($row['is_associated_with_current'] || 
           (!$row['is_associated_with_current'] && !$row['is_associated_elsewhere'])) 
        {
            $all_relevant_pallets[] = $row;
            if ($row['is_associated_with_current']) {
                $initially_associated_ids[] = $row['id'];
            }
        }
    }
    $stmt->close();

} catch (Exception $e) {
    $error_message = "Error fetching pallet data: " . $e->getMessage();
    $all_relevant_pallets = []; 
    $initially_associated_ids = [];
}


/* ─────────────────────────── UPDATE HANDLER ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pallet_associations'])) {

    /* CSRF */
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request (CSRF). Refresh the page and try again.");
    }

    $conn->begin_transaction();
    try {
        $selected_pallet_ids = isset($_POST['selected_pallets']) 
            ? array_map('intval', $_POST['selected_pallets']) 
            : [];
        $initial_ids_from_form = isset($_POST['initial_associated_ids']) 
            ? json_decode($_POST['initial_associated_ids'], true) 
            : [];
        if (!is_array($initial_ids_from_form)) {
            $initial_ids_from_form = [];
        }

        $pallets_to_add = array_diff($selected_pallet_ids, $initial_ids_from_form);
        $pallets_to_remove = array_diff($initial_ids_from_form, $selected_pallet_ids);

        $added_count = 0;
        $removed_count = 0;

        // Prepare statements
        $stmt_link = $conn->prepare("INSERT INTO delivery_pallets (delivery_id, inventory_pallet_id) VALUES (?, ?)");
        $stmt_update_status_add = $conn->prepare("UPDATE inventory_pallets SET status = 'Allocated to Delivery' WHERE id = ?");
        $stmt_unlink = $conn->prepare("DELETE FROM delivery_pallets WHERE delivery_id = ? AND inventory_pallet_id = ?");
        $stmt_get_status_remove = $conn->prepare("SELECT current_warehouse_id FROM inventory_pallets WHERE id = ?");
        // We'll re-prepare $stmt_update_status_remove inside the loop
        // to set the correct status for each pallet.

        if (!$stmt_link || !$stmt_update_status_add || !$stmt_unlink || !$stmt_get_status_remove) {
            throw new Exception("Error preparing statements: " . $conn->error);
        }

        // Add new associations
        foreach ($pallets_to_add as $pallet_id) {
            $stmt_link->bind_param("ii", $delivery_id, $pallet_id);
            if (!$stmt_link->execute()) {
                throw new Exception("Failed to link pallet ID {$pallet_id}: " . $stmt_link->error);
            }
            
            $stmt_update_status_add->bind_param("i", $pallet_id);
            if (!$stmt_update_status_add->execute()) {
                throw new Exception("Failed to update status for added pallet ID {$pallet_id}: " . $stmt_update_status_add->error);
            }
            $added_count++;
        }

        // Remove old associations
        foreach ($pallets_to_remove as $pallet_id) {
            $stmt_unlink->bind_param("ii", $delivery_id, $pallet_id);
            if (!$stmt_unlink->execute()) {
                throw new Exception("Failed to unlink pallet ID {$pallet_id}: " . $stmt_unlink->error);
            }
            
            // Determine the status to revert to
            $stmt_get_status_remove->bind_param("i", $pallet_id);
            $stmt_get_status_remove->execute();
            $stmt_get_status_remove->bind_result($warehouse_id);
            
            $new_status = 'At Manufacturer'; // Default
            if ($stmt_get_status_remove->fetch()) {
                if ($warehouse_id !== null) {
                    $new_status = 'In Warehouse';
                }
            }
            $stmt_get_status_remove->free_result();

            $stmt_update_status_remove = $conn->prepare("UPDATE inventory_pallets SET status = ? WHERE id = ?");
            if (!$stmt_update_status_remove) {
                throw new Exception("Failed to prepare status update for removal: " . $conn->error);
            }
            $stmt_update_status_remove->bind_param("si", $new_status, $pallet_id);
            if (!$stmt_update_status_remove->execute()) {
                throw new Exception("Failed to update status for removed pallet ID {$pallet_id}: " . $stmt_update_status_remove->error);
            }
            $stmt_update_status_remove->close();

            $removed_count++;
        }

        // Close remaining prepared statements
        $stmt_link->close();
        $stmt_update_status_add->close();
        $stmt_unlink->close();
        $stmt_get_status_remove->close();

        $conn->commit();
        $_SESSION['edit_delivery_success'] = 
            "Pallet associations updated. Added: {$added_count}, Removed: {$removed_count}.";

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['edit_delivery_error'] = 
            "Error updating pallet associations: " . $e->getMessage();
    }

    // Redirect back to edit_delivery page
    header("Location: edit_delivery.php?delivery_id=" . $delivery_id);
    exit;
}

/* ───────────────────────────── VIEW / FORM ────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Pallets for Delivery <?php echo $delivery_id; ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;600;700&display=swap" rel="stylesheet">
<style>
  main { max-width: 1000px; }
  .table-responsive { width: 100%; overflow-x: auto; margin-top: 15px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; font-size: 0.9em; }
  th { background-color: #e9ecef; font-weight: 600; }
  tr:nth-child(even) { background-color: #f8f9fa; }
  .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
  .success-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  .action-bar {
      margin-bottom: 20px;
      display: flex; 
      justify-content: flex-start;
      gap: 15px;
      align-items: center; 
  }
  .button {
      background:#488C9A; color:#fff; padding:10px 15px; 
      border:none; border-radius:4px; cursor:pointer; 
      font-size: 1em; text-decoration: none; display: inline-block;
  }
  .button:hover{ background:#3A6E7F }
  .button-secondary { background-color: #6c757d; }
  .button-secondary:hover { background-color: #5a6268; }
  .filter-controls { margin-bottom: 15px; background-color: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px solid #dee2e6; display: flex; gap: 15px; align-items: center;}
  .filter-controls label { font-weight: 500; margin-right: 5px; }
  .filter-controls input[type="text"] { padding: 6px; border: 1px solid #ced4da; border-radius: 4px; }
  .currently-associated { background-color: #e6ffed !important; } /* Highlight associated rows */
  .quantity-summary {
      background-color: #eef; 
      padding: 10px 15px; 
      border: 1px solid #ccd; 
      border-radius: 4px; 
      margin-bottom: 15px; 
      font-weight: 500; 
  }
  .quantity-summary span { margin-left: 10px; }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- 1) Move Back Button just below the site header -->
    <div style="margin-bottom:20px;">
      <a href="edit_delivery.php?delivery_id=<?php echo $delivery_id; ?>" class="button button-secondary">
        Back to Edit Delivery
      </a>
    </div>

    <h1>Manage Pallet Association</h1>
    <p>Delivery ID: <strong><?php echo $delivery_id; ?></strong></p>
    <p>Required Wattage: <strong><?php echo htmlspecialchars($required_wattage); ?>W</strong></p>

    <!-- Display messages -->
    <?php if ($success_message): ?>
        <p class="message success-message"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <p class="message error-message"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <!-- Quantity Summary -->
    <div class="quantity-summary">
        Required Quantity: <span id="requiredQty"><?php echo number_format($required_delivery_quantity); ?></span> |
        Currently Linked Quantity: <span id="linkedQty">0</span>
    </div>

    <!-- Main form -->
    <form 
      action="manage_delivery_pallets.php?delivery_id=<?php echo $delivery_id; ?>&wattage=<?php echo urlencode($required_wattage); ?>" 
      method="post"
    >
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="initial_associated_ids" 
               value="<?php echo htmlspecialchars(json_encode($initially_associated_ids)); ?>">

        <!-- 2) 'Add Pallets to Delivery' button at the top of the table -->
        <div class="action-bar">
            <button type="submit" name="save_pallet_associations" class="button">
                Add Pallets to Delivery
            </button>
        </div>

        <div class="filter-controls">
            <label for="palletSearch">Filter:</label>
            <input type="text" id="palletSearch" 
                   placeholder="By ID, Identifier, Status, Location..." 
                   onkeyup="filterTable()">
            <span style="margin-left: auto;" id="visibleCount"></span>
        </div>

        <div class="table-responsive">
            <table id="palletsTable">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" id="selectAll" title="Select/Deselect all visible">
                        </th>
                        <th>Identifier</th>
                        <th>Wattage</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Current Location</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($all_relevant_pallets)): ?>
                        <?php foreach ($all_relevant_pallets as $pallet): ?>
                            <?php 
                                $is_checked = in_array($pallet['id'], $initially_associated_ids); 
                                $row_class = $is_checked ? 'currently-associated' : '';
                            ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td>
                                    <input 
                                        type="checkbox"
                                        name="selected_pallets[]"
                                        value="<?php echo $pallet['id']; ?>"
                                        <?php echo $is_checked ? 'checked' : ''; ?>
                                        class="pallet-checkbox"
                                        data-quantity="<?php echo (int)$pallet['quantity']; ?>"
                                    >
                                </td>
                                <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                <td><?php echo number_format($pallet['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['warehouse_name'] ?? 'Manufacturer'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                No pallets found matching the required wattage (<?php echo htmlspecialchars($required_wattage); ?>W) 
                                that are either currently associated or available.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</main>

<script>
const requiredQuantity = <?php echo $required_delivery_quantity; ?>;

/**
 * Filters table rows by text input in #palletSearch
 */
function filterTable() {
    let filter = document.getElementById('palletSearch').value.toLowerCase();
    let table = document.getElementById('palletsTable');
    let rows = table.querySelectorAll('tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        let textContent = '';
        // Skip checkbox cell (index 0)
        for (let i = 1; i < row.cells.length; i++) {
            textContent += row.cells[i].textContent.toLowerCase() + ' ';
        }
        
        if (textContent.includes(filter)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    document.getElementById('visibleCount').textContent = `${visibleCount} pallet(s) visible`;
    updateSelectAllState(); // Update select-all based on new visibility
}

/**
 * Calculates the total quantity of checked/visible pallets
 * and updates the "Currently Linked Quantity" field.
 */
function updateLinkedQuantity() {
    let linkedQtySpan = document.getElementById('linkedQty');
    if (!linkedQtySpan) return;

    let currentLinkedTotal = 0;
    document.querySelectorAll('.pallet-checkbox:checked').forEach(checkbox => {
        // Only count quantity if row is visible
        if (checkbox.closest('tr').style.display !== 'none') {
            currentLinkedTotal += parseInt(checkbox.dataset.quantity || '0', 10);
        }
    });

    linkedQtySpan.textContent = currentLinkedTotal.toLocaleString();

    // Visual feedback if user exceeds or meets the required quantity
    let summaryDiv = document.querySelector('.quantity-summary');
    if (summaryDiv) {
        if (currentLinkedTotal > requiredQuantity) {
            summaryDiv.style.backgroundColor = '#f8d7da'; // Light red
            summaryDiv.style.borderColor = '#f5c6cb';
        } else if (currentLinkedTotal === requiredQuantity) {
            summaryDiv.style.backgroundColor = '#d4edda'; // Light green
            summaryDiv.style.borderColor = '#c3e6cb';
        } else {
            summaryDiv.style.backgroundColor = '#eef'; // Default
            summaryDiv.style.borderColor = '#ccd';
        }
    }
}

function updateSelectAllState() {
    let selectAllCheckbox = document.getElementById('selectAll');
    let checkboxes = document.querySelectorAll('.pallet-checkbox');
    let allVisibleChecked = true;
    let hasVisibleRows = false;

    checkboxes.forEach(cb => {
        let row = cb.closest('tr');
        if (row.style.display !== 'none') {
            hasVisibleRows = true;
            if (!cb.checked) {
                allVisibleChecked = false;
            }
        }
    });

    selectAllCheckbox.checked = hasVisibleRows && allVisibleChecked;
    selectAllCheckbox.indeterminate = (
        hasVisibleRows && 
        !allVisibleChecked && 
        Array.from(checkboxes).some(cb => cb.checked && cb.closest('tr').style.display !== 'none')
    );
}

// Toggle all checkboxes in visible rows
document.getElementById('selectAll').addEventListener('change', function() {
    let isChecked = this.checked;
    document.querySelectorAll('.pallet-checkbox').forEach(cb => {
        if (cb.closest('tr').style.display !== 'none') {
            cb.checked = isChecked;
        }
    });
    updateLinkedQuantity();
});

// On page load
document.addEventListener('DOMContentLoaded', () => {
    filterTable();       // Also updates selectAll state
    updateLinkedQuantity(); // Calculate initial linked quantity

    // Update linked quantity on any checkbox change
    document.querySelectorAll('.pallet-checkbox').forEach(cb => {
        cb.addEventListener('change', () => {
            updateSelectAllState();
            updateLinkedQuantity();
        });
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
