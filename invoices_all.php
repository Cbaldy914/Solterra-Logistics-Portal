<?php
session_name("logistics_session");
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

// 1) Get the user's account_id
$stmt = $conn->prepare("
    SELECT account_id 
    FROM customer_account_users 
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($account_id);
$stmt->fetch();
$stmt->close();

if (!$account_id) {
    die("No account found for this user.");
}

// 2) Calculate total open invoices (all projects) for this account
$stmt = $conn->prepare("
    SELECT SUM(i.amount) AS total_open
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    WHERE p.account_id = ?
      AND i.status = 'Open'
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$stmt->bind_result($total_open);
$stmt->fetch();
$stmt->close();

// 3) Calculate total amount past due (Open + overdue) for this account
$stmt = $conn->prepare("
    SELECT SUM(i.amount) AS total_past_due
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    WHERE p.account_id = ?
      AND i.status = 'Open'
      AND i.due_date < CURDATE()
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$stmt->bind_result($total_past_due);
$stmt->fetch();
$stmt->close();

// 4) Fetch all invoices for this account, across all projects
$stmt = $conn->prepare("
    SELECT i.id, i.invoice_file, i.uploaded_at, i.due_date, i.amount, i.status,
           p.project_name
    FROM project_invoices i
    JOIN projects p ON i.project_id = p.id
    WHERE p.account_id = ?
    ORDER BY i.uploaded_at DESC
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$invoices = [];
$statuses = [];

// 5) Dynamically adjust status if invoice is 'Open' but past due
while ($row = $result->fetch_assoc()) {
    $today = date('Y-m-d');
    if ($row['status'] === 'Open' && $row['due_date'] < $today) {
        $row['display_status'] = 'Past Due';
    } else {
        $row['display_status'] = $row['status'];
    }
    $invoices[] = $row;
    
    // Collect unique statuses for the filter
    if (!in_array($row['display_status'], $statuses)) {
        $statuses[] = $row['display_status'];
    }
}
$conn->close();

// Sort the statuses alphabetically
sort($statuses);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Invoices</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        /* Header Section - Matching global_documents.php */
        .global-documents-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .global-documents-header::before {
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

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
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
            min-width: 140px;
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

        .stat-item.danger .stat-number {
            color: #dc2626;
        }
        

        .invoice-summary, #invoices-table {
            border-collapse: collapse;
        }
        .invoice-summary th, .invoice-summary td, #invoices-table th, #invoices-table td {
            border: 1px solid #ccc;
            padding: 8px;
            white-space: nowrap;
        }
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .invoice-summary {
            width: 45%;
        }
        .controls-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .controls-container button {
            margin-right: 10px;
        }
        #search-input {
            padding: 5px;
        }
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
        .sort-dropdown-content a, .sort-dropdown-content div {
            color: black;
            padding: 8px 12px;
            text-decoration: none;
            display: block;
        }
        .sort-dropdown-content a:hover, .sort-dropdown-content div:hover {
            background-color: #f1f1f1;
        }
        .sort-dropdown.open .sort-dropdown-content {
            display: block;
        }
        .sort-icon {
            margin-left: 5px;
        }
        @media only screen and (max-width: 768px) {
            .invoice-summary {
                width: 45%;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs([
                'current_label' => 'Invoices'
            ]);
        ?>

        <div class="global-documents-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="header-info">
                        <h1>All Invoices</h1>
                        <p class="header-subtitle">Manage invoices across all your projects</p>
                    </div>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <p class="stat-number"><?php echo count($invoices); ?></p>
                        <p class="stat-label">Total Invoices</p>
                    </div>
                    <div class="stat-item">
                        <p class="stat-number">$<?php echo number_format($total_open ?: 0, 2); ?></p>
                        <p class="stat-label">Open Amount</p>
                    </div>
                    <div class="stat-item danger">
                        <p class="stat-number">$<?php echo number_format($total_past_due ?: 0, 2); ?></p>
                        <p class="stat-label">Past Due</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (count($invoices) > 0): ?>
            <form action="download_invoices" method="post">
                <!-- We'll pass the account_id in case your download_invoices script needs it -->
                <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <!-- Controls: Download Selected (Left) and Search (Right) -->
                <div class="controls-container">
                    <button type="submit" name="download_selected" onclick="return confirm('Download selected invoices?');">
                        Download Selected
                    </button>
                    <input type="text" id="search-input" placeholder="Search..." onkeyup="searchTable()">
                </div>

                <div class="table-responsive">
                    <table id="invoices-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th>
                                    Project Name
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(1, 'string', 'asc'); return false;">Sort A-Z</a>
                                            <a href="#" onclick="sortTable(1, 'string', 'desc'); return false;">Sort Z-A</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Invoice File
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(2, 'string', 'asc'); return false;">Sort A-Z</a>
                                            <a href="#" onclick="sortTable(2, 'string', 'desc'); return false;">Sort Z-A</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Due Date
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(3, 'date', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(3, 'date', 'desc'); return false;">Sort Descending</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Amount
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(4, 'currency', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(4, 'currency', 'desc'); return false;">Sort Descending</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Status
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(5, 'string', 'asc'); return false;">Sort A-Z</a>
                                            <a href="#" onclick="sortTable(5, 'string', 'desc'); return false;">Sort Z-A</a>
                                            <hr>
                                            <!-- Filter options for Status -->
                                            <div>
                                                <label><input type="checkbox" class="filter-checkbox" data-column="5" value="all" checked> Select All</label>
                                            </div>
                                            <?php foreach ($statuses as $st): ?>
                                                <div>
                                                    <label>
                                                        <input type="checkbox" class="filter-checkbox" data-column="5" value="<?php echo htmlspecialchars($st); ?>" checked> 
                                                        <?php echo htmlspecialchars($st); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Uploaded At
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(6, 'date', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(6, 'date', 'desc'); return false;">Sort Descending</a>
                                        </div>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="selected_invoices[]" value="<?php echo $invoice['id']; ?>">
                                </td>
                                <td><?php echo htmlspecialchars($invoice['project_name']); ?></td>
                                <td>
                                    <a href="view_invoice?invoice_id=<?php echo $invoice['id']; ?>" target="_blank">
                                        <?php echo htmlspecialchars(basename($invoice['invoice_file'])); ?>
                                    </a>
                                </td>
                                <td><?php echo date('F j, Y', strtotime($invoice['due_date'])); ?></td>
                                <td><?php echo number_format((float)$invoice['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($invoice['display_status']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['uploaded_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php else: ?>
            <p>No invoices found for your account.</p>
        <?php endif; ?>
    </main>

<script>
    // "Select All" functionality
    document.getElementById('select-all').onclick = function() {
        var checkboxes = document.getElementsByName('selected_invoices[]');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    };

    function searchTable() {
        var input, filter, table, tr, td, i, j, txtValue, visible;
        input = document.getElementById("search-input");
        filter = input.value.toLowerCase();
        table = document.getElementById("invoices-table");
        tr = table.getElementsByTagName("tr");

        for (i = 1; i < tr.length; i++) {
            // If row is already hidden by filters, skip it
            if (tr[i].style.display === 'none') {
                continue;
            }
            tr[i].style.display = "none";
            td = tr[i].getElementsByTagName("td");
            visible = false;
            // Search all table cells
            for (j = 0; j < td.length; j++) {
                if (td[j]) {
                    txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        visible = true;
                        break;
                    }
                }
            }
            if (visible) {
                tr[i].style.display = "";
            }
        }
    }

    function sortTable(columnIndex, type, order) {
        var table = document.getElementById("invoices-table");
        var tbody = table.tBodies[0];
        var rows = Array.from(tbody.rows);

        var compare;

        switch (type) {
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
                    var aText = a.cells[columnIndex].innerText.trim();
                    var bText = b.cells[columnIndex].innerText.trim();
                    var aValue = new Date(aText || '1970-01-01');
                    var bValue = new Date(bText || '1970-01-01');
                    return (order === 'asc') ? aValue - bValue : bValue - aValue;
                };
                break;
            case 'currency':
                compare = function(a, b) {
                    var aValue = parseFloat(a.cells[columnIndex].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                    var bValue = parseFloat(b.cells[columnIndex].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                    return (order === 'asc') ? aValue - bValue : bValue - aValue;
                };
                break;
            default:
                // fallback numeric
                compare = function(a, b) {
                    var aValue = parseFloat(a.cells[columnIndex].innerText) || 0;
                    var bValue = parseFloat(b.cells[columnIndex].innerText) || 0;
                    return (order === 'asc') ? aValue - bValue : bValue - aValue;
                };
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

    // Filtering by status checkboxes
    document.addEventListener('DOMContentLoaded', function() {
        var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
        filterCheckboxes.forEach(function(checkbox) {
            // Prevent dropdown from closing when clicking a checkbox
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            checkbox.addEventListener('change', function() {
                var columnIndex = parseInt(this.getAttribute('data-column'));
                var value = this.value;

                if (value === 'all') {
                    // 'Select All' checkbox changed
                    var checked = this.checked;
                    var checkboxes = document.querySelectorAll('.filter-checkbox[data-column="' + columnIndex + '"]');
                    checkboxes.forEach(function(cb) {
                        cb.checked = checked;
                    });
                } else {
                    // Individual status checkbox changed
                    var allChecked = true;
                    var checkboxes = document.querySelectorAll('.filter-checkbox[data-column="' + columnIndex + '"]:not([value="all"])');
                    checkboxes.forEach(function(cb) {
                        if (!cb.checked) {
                            allChecked = false;
                        }
                    });
                    var selectAllCheckbox = document.querySelector('.filter-checkbox[data-column="' + columnIndex + '"][value="all"]');
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = allChecked;
                    }
                }
                applyFilters();
            });
        });

        // close dropdowns if clicking outside
        var sortIcons = document.querySelectorAll('.sort-icon');
        sortIcons.forEach(function(icon) {
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                closeAllDropdowns();
                var dropdown = icon.parentElement;
                dropdown.classList.toggle('open');
            });
        });
        document.addEventListener('click', function(e) {
            var isInside = e.target.closest('.sort-dropdown-content');
            if (!isInside) {
                closeAllDropdowns();
            }
        });
        var dropdownContents = document.querySelectorAll('.sort-dropdown-content');
        dropdownContents.forEach(function(content) {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // apply initial filters
        applyFilters();
    });

    function closeAllDropdowns() {
        var dropdowns = document.querySelectorAll('.sort-dropdown');
        dropdowns.forEach(function(dd) {
            dd.classList.remove('open');
        });
    }

    function applyFilters() {
        var table = document.getElementById('invoices-table');
        var tbody = table.tBodies[0];
        var rows = tbody.getElementsByTagName('tr');

        // Gather filters by column
        var filters = {};
        var columns = {};

        var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
        filterCheckboxes.forEach(function(checkbox) {
            var columnIndex = parseInt(checkbox.getAttribute('data-column'));
            if (!columns[columnIndex]) {
                columns[columnIndex] = {
                    checkboxes: [],
                    selectAllCheckbox: null
                };
            }
            if (checkbox.value === 'all') {
                columns[columnIndex].selectAllCheckbox = checkbox;
            } else {
                columns[columnIndex].checkboxes.push(checkbox);
            }
        });

        for (var colIndex in columns) {
            var colData = columns[colIndex];
            var selectedValues = [];
            if (colData.selectAllCheckbox && colData.selectAllCheckbox.checked) {
                filters[colIndex] = null; // no filter
                colData.checkboxes.forEach(function(cb) {
                    cb.checked = true;
                });
            } else {
                colData.checkboxes.forEach(function(cb) {
                    if (cb.checked) {
                        selectedValues.push(cb.value.toLowerCase());
                    }
                });
                filters[colIndex] = selectedValues;
            }
            // update 'Select All' if needed
            if (colData.selectAllCheckbox) {
                var allChecked = colData.checkboxes.every(function(cb) { return cb.checked; });
                colData.selectAllCheckbox.checked = allChecked;
            }
        }

        // now apply the filters row-by-row
        for (var i = 1; i < rows.length; i++) {
            var row = rows[i];
            var showRow = true;
            for (var columnIndex in filters) {
                var selectedVals = filters[columnIndex];
                if (selectedVals !== null) {
                    var cellText = (row.cells[columnIndex] && row.cells[columnIndex].innerText.toLowerCase()) || '';
                    if (selectedVals.indexOf(cellText) === -1) {
                        showRow = false;
                        break;
                    }
                }
            }
            row.style.display = showRow ? '' : 'none';
        }
        // apply text search after the status filters
        searchTable();
    }
</script>
</body>
</html>
