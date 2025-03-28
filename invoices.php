<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify that the project belongs to the user
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project.");
}

// Get total open invoices
$stmt = $conn->prepare("
    SELECT SUM(amount) AS total_open
    FROM project_invoices
    WHERE project_id = ? AND status = 'Open'
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($total_open);
$stmt->fetch();
$stmt->close();

// Get total amount past due (dynamically treat Open & overdue as Past Due)
$stmt = $conn->prepare("
    SELECT SUM(amount) AS total_past_due
    FROM project_invoices
    WHERE project_id = ?
      AND status = 'Open'
      AND due_date < CURDATE()
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$stmt->bind_result($total_past_due);
$stmt->fetch();
$stmt->close();

// Fetch invoices for the project
$stmt = $conn->prepare("
    SELECT id, invoice_file, uploaded_at, due_date, amount, status
    FROM project_invoices
    WHERE project_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$invoices = [];
$statuses = [];

// Dynamically adjust status if invoice is Open but past due
while ($row = $result->fetch_assoc()) {
    $today = date('Y-m-d');
    if ($row['status'] === 'Open' && $row['due_date'] < $today) {
        $row['display_status'] = 'Past Due';
    } else {
        $row['display_status'] = $row['status'];
    }

    $invoices[] = $row;

    // Collect display statuses for filtering and sorting
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
    <title>Invoices - <?php echo htmlspecialchars($project_name); ?></title>
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

        /* Controls container to align Download button on left and Search on right */
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
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
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
        <a href="#" onclick="if(document.referrer) { window.location = document.referrer; } else { window.history.back(); }" class="back-icon" style="margin:20px;">
            <!-- SVG for Back Arrow -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
            </svg>
            Back
        </a>
        <div class="page-header">
            <h1>Invoices for <?php echo htmlspecialchars($project_name); ?></h1>
        </div>

        <!-- Summary Table -->
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
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

                <!-- Controls: Download Selected (Left) and Search (Right) -->
                <div class="controls-container">
                    <button type="submit" name="download_selected" onclick="return confirm('Download selected invoices?');">Download Selected</button>
                    <input type="text" id="search-input" placeholder="Search..." onkeyup="searchTable()">
                </div>

                <div class="table-responsive">
                    <table id="invoices-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all"></th>
                                <th>
                                    Invoice File
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(1, 'string', 'asc'); return false;">Sort A-Z</a>
                                            <a href="#" onclick="sortTable(1, 'string', 'desc'); return false;">Sort Z-A</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Due Date
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(2, 'date', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(2, 'date', 'desc'); return false;">Sort Descending</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Amount
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(3, 'currency', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(3, 'currency', 'desc'); return false;">Sort Descending</a>
                                        </div>
                                    </div>
                                </th>
                                <th>
                                    Status
                                    <div class="sort-dropdown">
                                        <span class="sort-icon">&#9660;</span>
                                        <div class="sort-dropdown-content">
                                            <a href="#" onclick="sortTable(4, 'string', 'asc'); return false;">Sort A-Z</a>
                                            <a href="#" onclick="sortTable(4, 'string', 'desc'); return false;">Sort Z-A</a>
                                            <hr>
                                            <!-- Filter options for Status -->
                                            <div>
                                                <label><input type="checkbox" class="filter-checkbox" data-column="4" value="all" checked> Select All</label>
                                            </div>
                                            <?php foreach ($statuses as $st): ?>
                                                <div>
                                                    <label><input type="checkbox" class="filter-checkbox" data-column="4" value="<?php echo htmlspecialchars($st); ?>" checked> <?php echo htmlspecialchars($st); ?></label>
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
                                            <a href="#" onclick="sortTable(5, 'date', 'asc'); return false;">Sort Ascending</a>
                                            <a href="#" onclick="sortTable(5, 'date', 'desc'); return false;">Sort Descending</a>
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
                                <td>
                                    <a href="view_invoice?invoice_id=<?php echo $invoice['id']; ?>" target="_blank">
                                        <?php echo htmlspecialchars(basename($invoice['invoice_file'])); ?>
                                    </a>
                                </td>
                                <td><?php echo date('F j, Y', strtotime($invoice['due_date'])); ?></td>
                                <td><?php echo number_format((float)$invoice['amount'], 2); ?></td>
                                <!-- Display the dynamically adjusted status -->
                                <td><?php echo htmlspecialchars($invoice['display_status']); ?></td>
                                <td><?php echo htmlspecialchars($invoice['uploaded_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php else: ?>
            <p>No invoices found for this project.</p>
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
        // Loop through all table rows, except the first one (header)
        for (i = 1; i < tr.length; i++) {
            // If row is already hidden by filters, skip it
            if (tr[i].style.display === 'none') {
                continue;
            }
            tr[i].style.display = "none";
            td = tr[i].getElementsByTagName("td");
            visible = false;
            // Loop through all table cells in the row
            for (j = 0; j < td.length; j++) {
                if (td[j]) {
                    txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        visible = true;
                        break;
                    }
                }
            }
            if (visible === true) {
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
            case 'num':
                compare = function(a, b) {
                    var aValue = parseFloat(a.cells[columnIndex].innerText.replace(/[^0-9.-]+/g,"")) || 0;
                    var bValue = parseFloat(b.cells[columnIndex].innerText.replace(/[^0-9.-]+/g,"")) || 0;
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
                    var aValue = new Date(aText.trim() === '' || aText === 'N/A' ? '1970-01-01' : aText);
                    var bValue = new Date(bText.trim() === '' || bText === 'N/A' ? '1970-01-01' : bText);
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
                return;
        }

        rows.sort(compare);

        // Remove existing rows
        while (tbody.firstChild) {
            tbody.removeChild(tbody.firstChild);
        }

        // Append sorted rows
        rows.forEach(function(row) {
            tbody.appendChild(row);
        });

        // Reapply filters after sorting
        applyFilters();
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Add event listeners to filter checkboxes
        var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
        filterCheckboxes.forEach(function(checkbox) {
            // Prevent dropdown from closing when clicking on a checkbox
            checkbox.addEventListener('click', function(event) {
                event.stopPropagation();
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
                    // Individual checkbox changed
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

        // Event listeners for dropdown toggling
        var sortIcons = document.querySelectorAll('.sort-icon');
        sortIcons.forEach(function(icon) {
            icon.addEventListener('click', function(event) {
                event.stopPropagation();
                closeAllDropdowns();
                var dropdown = icon.parentElement;
                dropdown.classList.toggle('open');
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            var isClickInsideDropdown = event.target.closest('.sort-dropdown-content');
            if (!isClickInsideDropdown) {
                closeAllDropdowns();
            }
        });

        // Prevent dropdown from closing when clicking inside
        var dropdownContents = document.querySelectorAll('.sort-dropdown-content');
        dropdownContents.forEach(function(content) {
            content.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        });

        // Initial application of filters
        applyFilters();
    });

    function closeAllDropdowns() {
        var dropdowns = document.querySelectorAll('.sort-dropdown');
        dropdowns.forEach(function(dropdown) {
            dropdown.classList.remove('open');
        });
    }

    function applyFilters() {
        var table = document.getElementById('invoices-table');
        var tbody = table.tBodies[0];
        var rows = tbody.getElementsByTagName('tr');

        var filters = {};

        // Get all filter columns
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

        for (var columnIndex in columns) {
            var column = columns[columnIndex];
            var selectedValues = [];

            if (column.selectAllCheckbox && column.selectAllCheckbox.checked) {
                filters[columnIndex] = null; // No filtering needed
                // Ensure all individual checkboxes are checked
                column.checkboxes.forEach(function(cb) {
                    cb.checked = true;
                });
            } else {
                column.checkboxes.forEach(function(checkbox) {
                    if (checkbox.checked) {
                        selectedValues.push(checkbox.value.toLowerCase());
                    }
                });
                filters[columnIndex] = selectedValues;
            }

            // Update 'Select All' checkbox state
            if (column.selectAllCheckbox) {
                var allChecked = column.checkboxes.every(function(checkbox) {
                    return checkbox.checked;
                });
                column.selectAllCheckbox.checked = allChecked;
            }
        }

        // Now apply the filters
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var showRow = true;
            for (var columnIndex in filters) {
                var selectedValues = filters[columnIndex];
                if (selectedValues !== null) { // If not null, we have filters to apply
                    var cellText = (row.cells[parseInt(columnIndex)] && row.cells[parseInt(columnIndex)].innerText.toLowerCase()) || '';
                    if (selectedValues.indexOf(cellText) === -1) {
                        showRow = false;
                        break;
                    }
                }
            }
            row.style.display = showRow ? '' : 'none';
        }

        // Apply search filter after applying other filters
        searchTable();
    }
</script>

</body>
</html>
