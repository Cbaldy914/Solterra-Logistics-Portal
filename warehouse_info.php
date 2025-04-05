<?php
session_name("logistics_session");
session_start();

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Unable to connect to database. Please try again later.");
}

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

// Determine if user has access to the project based on role/account membership
$project_name = '';

if ($role === 'admin' || $role === 'global_admin') {
    // Admin or global_admin => can see any project, so just confirm it exists
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular user => check if they belong to the same account as the project
    $stmt = $conn->prepare("
        SELECT p.project_name
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ?
          AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    // Project not found or user has no access
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied</title>
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="portal.css">
        <style>
            .back-icon { text-decoration: none; color: #333; display: inline-flex; align-items: center; margin-bottom: 20px; }
            .back-icon svg { width: 24px; height: 24px; margin-right: 5px; }
            main { margin: 20px; }
        </style>
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main>
        <a href="#" onclick="if(document.referrer) { window.location = document.referrer; } else { window.history.back(); }" class="back-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
            </svg>
            Back
        </a>
        <p>You do not have access to this project or it does not exist.</p>
    </main>
    </body>
    </html>
    <?php
    exit();
}

// Now fetch warehouse info for the project
$stmt = $conn->prepare("
    SELECT w.id AS warehouse_id, w.name, w.address, w.image_url, 
           w.in_fee, w.out_fee, w.monthly_storage_fee
    FROM warehouses w
    JOIN projects p ON p.warehouse_id = w.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$warehouse_result = $stmt->get_result();
$stmt->close();

// If no warehouse is associated
if ($warehouse_result->num_rows == 0) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>No Warehouse Information</title>
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="portal.css">
        <style>
            .back-icon { text-decoration: none; color: #333; display: inline-flex; align-items: center; margin-bottom: 20px; }
            .back-icon svg { width: 24px; height: 24px; margin-right: 5px; }
            main { margin: 20px; }
        </style>
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main>
        <a href="#" onclick="if(document.referrer) { window.location = document.referrer; } else { window.history.back(); }" class="back-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
            </svg>
            Back
        </a>
        <h1>Warehouse information not found for project <?php echo htmlspecialchars($project_name); ?>.</h1>
    </main>
    </body>
    </html>
    <?php
    exit();
}

// Warehouse found
$warehouse = $warehouse_result->fetch_assoc();

// We'll gather additional info about warehousing costs, deliveries, etc.
// ... the rest is your original logic ...

$warehouse_id = $warehouse['warehouse_id'];

// Count inbound deliveries
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total_deliveries
    FROM deliveries
    WHERE project_id = ? AND warehouse_arrival_date IS NOT NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($total_deliveries);
$stmt->fetch();
$stmt->close();

// Count outbound deliveries
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total_deliveries_out
    FROM deliveries
    WHERE project_id = ? AND left_warehouse_date IS NOT NULL AND warehouse_arrival_date IS NOT NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($total_deliveries_out);
$stmt->fetch();
$stmt->close();

// Calculate in/out fee cost
$in_fee_cost  = $warehouse['in_fee']  * $total_deliveries;
$out_fee_cost = $warehouse['out_fee'] * $total_deliveries_out;

// Modules in storage
$stmt = $conn->prepare("
    SELECT SUM(quantity) AS total_modules
    FROM deliveries
    WHERE project_id = ? 
      AND warehouse_arrival_date IS NOT NULL
      AND left_warehouse_date IS NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($total_modules);
$stmt->fetch();
$stmt->close();
$total_modules = $total_modules ?: 0;

// Deliveries in storage
$stmt = $conn->prepare("
    SELECT COUNT(*) AS deliveries_in_storage
    FROM deliveries
    WHERE project_id = ?
      AND warehouse_arrival_date IS NOT NULL
      AND left_warehouse_date IS NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($deliveries_in_storage);
$stmt->fetch();
$stmt->close();
$deliveries_in_storage = $deliveries_in_storage ?: 0;

// Current monthly storage cost
$monthly_storage_cost = $deliveries_in_storage * $warehouse['monthly_storage_fee'];

// Calculate total storage cost to date for all deliveries that have been in the warehouse
$stmt = $conn->prepare("
    SELECT id, warehouse_arrival_date, left_warehouse_date
    FROM deliveries
    WHERE project_id = ?
      AND warehouse_arrival_date IS NOT NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$all_deliveries_result = $stmt->get_result();
$stmt->close();

$total_storage_cost = 0;
while ($delivery = $all_deliveries_result->fetch_assoc()) {
    $start_date = new DateTime($delivery['warehouse_arrival_date']);
    $end_date   = !empty($delivery['left_warehouse_date'])
                    ? new DateTime($delivery['left_warehouse_date'])
                    : new DateTime();

    $interval        = $start_date->diff($end_date);
    $days_in_storage = $interval->days + 1;
    $daily_storage   = $warehouse['monthly_storage_fee'] / 30;
    $total_storage_cost += ($days_in_storage * $daily_storage);
}

// Sum it all
$total_cost_to_date = $in_fee_cost + $out_fee_cost + $total_storage_cost;

// Now fetch all deliveries that have arrived
$stmt = $conn->prepare("
    SELECT 
      id, supplier, wattage, quantity, bol_number, 
      warehouse_arrival_date, left_warehouse_date, proof_of_delivery
    FROM deliveries
    WHERE project_id = ?
      AND warehouse_arrival_date IS NOT NULL
    ORDER BY warehouse_arrival_date ASC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$deliveries_result = $stmt->get_result();
$stmt->close();

$deliveries = [];
$supplier_values              = [];
$wattage_values               = [];
$bol_number_values            = [];
$warehouse_arrival_date_values= [];
$left_warehouse_date_values   = [];

while ($drow = $deliveries_result->fetch_assoc()) {
    $deliveries[] = $drow;
    $supplier_values[]    = $drow['supplier']            ?? '';
    $wattage_values[]     = $drow['wattage']             ?? '';
    $bol_number_values[]  = $drow['bol_number']          ?? '';
    $warehouse_arrival_date_values[] = $drow['warehouse_arrival_date'] ?? '';
    $left_warehouse_date_values[]    = $drow['left_warehouse_date']    ?? '';
}

$conn->close();

// Remove duplicates
$supplier_values               = array_unique($supplier_values);
$wattage_values                = array_unique($wattage_values);
$bol_number_values             = array_unique($bol_number_values);
$warehouse_arrival_date_values = array_unique($warehouse_arrival_date_values);
$left_warehouse_date_values    = array_unique($left_warehouse_date_values);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Warehousing Information for <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .back-icon {
            text-decoration: none; 
            color: #333; 
            display: inline-flex; 
            align-items: center; 
            margin-bottom: 20px;
        }
        .back-icon svg {
            width: 24px; 
            height: 24px; 
            margin-right: 5px;
        }

        .warehouse-info {
            display: flex;
            margin-bottom: 20px;
        }
        .warehouse-image img {
            max-width: 200px;
            margin-right: 20px;
        }
        .warehouse-details p {
            margin: 5px 0;
        }
        .cost-summary {
            margin-bottom: 20px;
            width: 100%;
            border-collapse: collapse;
        }
        .cost-summary th,
        .cost-summary td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: center;
            white-space: nowrap;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            position: relative;
        }
        .scroll-note {
            display: none;
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        @media screen and (max-width: 768px) {
            .warehouse-info {
                flex-direction: column;
                align-items: center;
            }
            .warehouse-image img {
                max-width: 100%;
                margin-bottom: 20px;
            }
            .cost-summary th, .cost-summary td {
                font-size: 14px;
                white-space: normal;
            }
            .scroll-note {
                display: block;
            }
        }
        .controls-container {
            text-align: right;
            margin-bottom: 20px;
        }
        .controls-container form,
        .controls-container input[type="text"] {
            display: inline-block;
            vertical-align: middle;
        }
        .btn-edit-warehouse {
            color: #488C9A;
            text-decoration: none;
            font-weight: bold;
        }
        /* Sorting/Filtering styles */
        .sort-dropdown {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }
        .sort-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: #f9f9f9;
            min-width: 200px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
        }
        .sort-dropdown-content a,
        .sort-dropdown-content div {
            color: black;
            padding: 8px 12px;
            text-decoration: none;
            display: block;
        }
        .sort-dropdown-content a:hover,
        .sort-dropdown-content div:hover {
            background-color: #f1f1f1;
        }
        .sort-dropdown.open .sort-dropdown-content {
            display: block;
        }
        .sort-icon {
            margin-left: 5px;
        }
        .filter-checkbox {
            margin-right: 5px;
        }
        table, th, td {
            border: 1px solid #ccc;
        }
        th, td {
            padding: 8px;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="#" onclick="if(document.referrer) window.location=document.referrer; else window.history.back();" class="back-icon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>

    <h1>Warehousing Information for <?php echo htmlspecialchars($project_name); ?></h1>

    <div class="warehouse-info">
        <div class="warehouse-image">
            <?php if (!empty($warehouse['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($warehouse['image_url']); ?>" alt="Warehouse Image">
            <?php endif; ?>
        </div>
        <div class="warehouse-details">
            <h2><?php echo htmlspecialchars($warehouse['name']); ?></h2>
            <p><strong>Address:</strong> <?php echo htmlspecialchars($warehouse['address']); ?></p>
            <p><strong>In Fee:</strong> $<?php echo number_format($warehouse['in_fee'], 2); ?></p>
            <p><strong>Out Fee:</strong> $<?php echo number_format($warehouse['out_fee'], 2); ?></p>
            <p><strong>Monthly Storage Fee:</strong> $<?php echo number_format($warehouse['monthly_storage_fee'], 2); ?></p>
            <?php if ($role === 'admin'): ?>
                <p><a href="edit_warehouse?warehouse_id=<?php echo $warehouse_id; ?>" class="btn-edit-warehouse">Edit Warehouse Information</a></p>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Show cost summary
    ?>
    <div class="table-responsive">
        <table class="cost-summary">
            <tr>
                <th>Total Modules in Storage</th>
                <th>In Fee Cost</th>
                <th>Out Fee Cost</th>
                <th>Monthly Storage Cost</th>
                <th>Total Cost to Date</th>
            </tr>
            <tr>
                <td><?php echo number_format($total_modules); ?></td>
                <td>$<?php echo number_format($in_fee_cost, 2); ?></td>
                <td>$<?php echo number_format($out_fee_cost, 2); ?></td>
                <td>$<?php echo number_format($monthly_storage_cost, 2); ?></td>
                <td>$<?php echo number_format($total_cost_to_date, 2); ?></td>
            </tr>
        </table>
    </div>
    <div class="scroll-note">Swipe or scroll horizontally to see more columns.</div>

    <div class="controls-container">
        <!-- Potential future controls (search, etc.) -->
    </div>

    <?php
    // Now display the deliveries that are/have been in this warehouse
    ?>
    <div class="table-responsive">
        <table id="warehouse-table">
            <thead>
                <tr>
                    <th>
                        Supplier
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(0, 'string', 'asc'); return false;">Sort A-Z</a>
                                <a href="#" onclick="sortTable(0, 'string', 'desc'); return false;">Sort Z-A</a>
                                <hr>
                                <div>
                                    <label><input type="checkbox" class="filter-checkbox" data-column="0" value="all" checked> Select All</label>
                                </div>
                                <?php foreach ($supplier_values as $value): ?>
                                    <?php if (trim($value) !== ''): ?>
                                        <div>
                                            <label>
                                                <input type="checkbox" class="filter-checkbox" data-column="0" value="<?php echo htmlspecialchars($value); ?>" checked>
                                                <?php echo htmlspecialchars($value); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </th>
                    <th>
                        Wattage
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(1, 'num', 'asc'); return false;">Sort Asc</a>
                                <a href="#" onclick="sortTable(1, 'num', 'desc'); return false;">Sort Desc</a>
                                <hr>
                                <div>
                                    <label><input type="checkbox" class="filter-checkbox" data-column="1" value="all" checked> Select All</label>
                                </div>
                                <?php foreach ($wattage_values as $value): ?>
                                    <?php if (trim($value) !== ''): ?>
                                        <div>
                                            <label>
                                                <input type="checkbox" class="filter-checkbox" data-column="1" value="<?php echo htmlspecialchars($value); ?>" checked>
                                                <?php echo htmlspecialchars($value); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </th>
                    <th>
                        Quantity
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(2, 'num', 'asc'); return false;">Sort Asc</a>
                                <a href="#" onclick="sortTable(2, 'num', 'desc'); return false;">Sort Desc</a>
                            </div>
                        </div>
                    </th>
                    <th>
                        BOL Number
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(3, 'string', 'asc'); return false;">Sort A-Z</a>
                                <a href="#" onclick="sortTable(3, 'string', 'desc'); return false;">Sort Z-A</a>
                                <hr>
                                <div>
                                    <label><input type="checkbox" class="filter-checkbox" data-column="3" value="all" checked> Select All</label>
                                </div>
                                <?php foreach ($bol_number_values as $value): ?>
                                    <?php if (trim($value) !== ''): ?>
                                        <div>
                                            <label>
                                                <input type="checkbox" class="filter-checkbox" data-column="3" value="<?php echo htmlspecialchars($value); ?>" checked>
                                                <?php echo htmlspecialchars($value); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </th>
                    <th>
                        Warehouse Arrival Date
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(4, 'date', 'asc'); return false;">Sort Asc</a>
                                <a href="#" onclick="sortTable(4, 'date', 'desc'); return false;">Sort Desc</a>
                                <hr>
                                <div>
                                    <label><input type="checkbox" class="filter-checkbox" data-column="4" value="all" checked> Select All</label>
                                </div>
                                <?php foreach ($warehouse_arrival_date_values as $value): ?>
                                    <?php if (trim($value) !== ''): ?>
                                        <div>
                                            <label>
                                                <input type="checkbox" class="filter-checkbox" data-column="4" value="<?php echo htmlspecialchars($value); ?>" checked>
                                                <?php echo htmlspecialchars($value); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </th>
                    <th>
                        Left Warehouse Date
                        <div class="sort-dropdown">
                            <span class="sort-icon">&#9660;</span>
                            <div class="sort-dropdown-content">
                                <a href="#" onclick="sortTable(5, 'date', 'asc'); return false;">Sort Asc</a>
                                <a href="#" onclick="sortTable(5, 'date', 'desc'); return false;">Sort Desc</a>
                                <hr>
                                <div>
                                    <label><input type="checkbox" class="filter-checkbox" data-column="5" value="all" checked> Select All</label>
                                </div>
                                <?php foreach ($left_warehouse_date_values as $value): ?>
                                    <?php if (trim($value) !== ''): ?>
                                        <div>
                                            <label>
                                                <input type="checkbox" class="filter-checkbox" data-column="5" value="<?php echo htmlspecialchars($value); ?>" checked>
                                                <?php echo htmlspecialchars($value); ?>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </th>
                    <th>Proof of Delivery</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($deliveries)): ?>
                    <?php foreach ($deliveries as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['supplier'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($d['wattage'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($d['quantity'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($d['bol_number'] ?? ''); ?></td>
                            <td><?php echo !empty($d['warehouse_arrival_date']) ? htmlspecialchars($d['warehouse_arrival_date']) : 'N/A'; ?></td>
                            <td><?php echo !empty($d['left_warehouse_date']) ? htmlspecialchars($d['left_warehouse_date']) : 'N/A'; ?></td>
                            <td>
                                <?php if (!empty($d['proof_of_delivery'])): ?>
                                    <a href="view_pod?delivery_id=<?php echo $d['id']; ?>" target="_blank">View POD</a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No deliveries found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="scroll-note">Swipe or scroll horizontally to see more columns.</div>
</main>

<script>
function sortTable(columnIndex, type, order) {
    var table = document.getElementById("warehouse-table");
    var tbody = table.tBodies[0];
    var rows = Array.from(tbody.rows);

    var compare;
    switch (type) {
        case 'num':
            compare = function(a, b) {
                var aValue = parseFloat(a.cells[columnIndex].innerText || a.cells[columnIndex].textContent) || 0;
                var bValue = parseFloat(b.cells[columnIndex].innerText || b.cells[columnIndex].textContent) || 0;
                return (order === 'asc') ? aValue - bValue : bValue - aValue;
            };
            break;
        case 'string':
            compare = function(a, b) {
                var aValue = (a.cells[columnIndex].innerText || a.cells[columnIndex].textContent).toLowerCase();
                var bValue = (b.cells[columnIndex].innerText || b.cells[columnIndex].textContent).toLowerCase();
                if (aValue < bValue) return (order === 'asc') ? -1 : 1;
                if (aValue > bValue) return (order === 'asc') ? 1 : -1;
                return 0;
            };
            break;
        case 'date':
            compare = function(a, b) {
                var aText = a.cells[columnIndex].innerText || a.cells[columnIndex].textContent;
                var bText = b.cells[columnIndex].innerText || b.cells[columnIndex].textContent;
                var aValue = new Date(aText.trim() === '' ? '1970-01-01' : aText);
                var bValue = new Date(bText.trim() === '' ? '1970-01-01' : bText);
                return (order === 'asc') ? aValue - bValue : bValue - aValue;
            };
            break;
        default:
            return;
    }

    rows.sort(compare);
    while (tbody.firstChild) {
        tbody.removeChild(tbody.firstChild);
    }
    rows.forEach(function(row) {
        tbody.appendChild(row);
    });
    applyFilters();
}

document.addEventListener('DOMContentLoaded', function() {
    var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
    filterCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        checkbox.addEventListener('change', function() {
            var columnIndex = this.getAttribute('data-column');
            var value = this.value;
            if (value === 'all') {
                var checked = this.checked;
                var checkboxes = document.querySelectorAll('.filter-checkbox[data-column="' + columnIndex + '"]');
                checkboxes.forEach(function(cb) {
                    cb.checked = checked;
                });
            } else {
                var allChecked = true;
                var checkboxes = document.querySelectorAll('.filter-checkbox[data-column="' + columnIndex + '"]:not([value="all"])');
                checkboxes.forEach(function(cb) {
                    if (!cb.checked) {
                        allChecked = false;
                    }
                });
                var selectAllCheckbox = document.querySelector('.filter-checkbox[data-column="' + columnIndex + '"][value="all"]');
                selectAllCheckbox.checked = allChecked;
            }
            applyFilters();
        });
    });

    var sortIcons = document.querySelectorAll('.sort-icon');
    sortIcons.forEach(function(icon) {
        icon.addEventListener('click', function(event) {
            event.stopPropagation();
            closeAllDropdowns();
            var dropdown = icon.parentElement;
            dropdown.classList.toggle('open');
        });
    });

    document.addEventListener('click', function(e) {
        var isInsideContent = e.target.closest('.sort-dropdown-content');
        if (!isInsideContent) {
            closeAllDropdowns();
        }
    });

    var dropdownContents = document.querySelectorAll('.sort-dropdown-content');
    dropdownContents.forEach(function(content) {
        content.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});

function closeAllDropdowns() {
    var dropdowns = document.querySelectorAll('.sort-dropdown');
    dropdowns.forEach(function(dd) {
        dd.classList.remove('open');
    });
}

function applyFilters() {
    var table = document.getElementById('warehouse-table');
    var tbody = table.tBodies[0];
    var rows = tbody.getElementsByTagName('tr');

    var filters = {};
    var columns = {};

    var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
    filterCheckboxes.forEach(function(cb) {
        var columnIndex = cb.getAttribute('data-column');
        if (!columns[columnIndex]) {
            columns[columnIndex] = {
                checkboxes: [],
                selectAllCheckbox: null
            };
        }
        if (cb.value === 'all') {
            columns[columnIndex].selectAllCheckbox = cb;
        } else {
            columns[columnIndex].checkboxes.push(cb);
        }
    });

    for (var colIndex in columns) {
        var column = columns[colIndex];
        var selectedValues = [];

        if (column.selectAllCheckbox.checked) {
            filters[colIndex] = null;
            column.checkboxes.forEach(function(cb) { cb.checked = true; });
        } else {
            column.checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    selectedValues.push(cb.value.toLowerCase());
                }
            });
            filters[colIndex] = selectedValues;
        }

        var allChecked = column.checkboxes.every(function(cb) { return cb.checked; });
        column.selectAllCheckbox.checked = allChecked;
    }

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var showRow = true;
        for (var colIndex in filters) {
            var selectedVals = filters[colIndex];
            if (selectedVals !== null) {
                var cellText = (row.cells[colIndex].innerText || row.cells[colIndex].textContent).toLowerCase();
                if (selectedVals.indexOf(cellText) === -1) {
                    showRow = false;
                    break;
                }
            }
        }
        row.style.display = showRow ? '' : 'none';
    }
}
</script>
</body>
</html>
