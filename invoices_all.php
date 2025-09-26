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

    <style>
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

        <div class="page-header">
            <h1>All Invoices for Your Account</h1>
        </div>

        <!-- Summary Table for the entire account -->
        <div class="table-responsive">
            <table class="invoice-summary">
                <tr>
                    <th>Total Open Invoices</th>
                    <th>Total Amount Past Due</th>
                </tr>
                <tr>
                    <td><?php echo number_format($total_open ?: 0, 2); ?></td>
                    <td><?php echo number_format($total_past_due ?: 0, 2); ?></td>
                </tr>
            </table>
        </div>

        <?php if (count($invoices) > 0): ?>
            <form action="download_invoices" method="post">
                <!-- We'll pass the account_id in case your download_invoices script needs it -->
                <input type="hidden" name="account_id" value="<?php echo $account_id; ?>">

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
