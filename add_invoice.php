<?php
session_name("logistics_session");
session_start();



// Check if the user is logged in and is a global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize variables
$success_message = '';
$error_message = '';

// Handle deletion of invoice
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $invoice_id = intval($_GET['id']);

    // Fetch the invoice to get the file path
    $stmt = $conn->prepare("SELECT invoice_file FROM project_invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $stmt->bind_result($invoice_file);
    $stmt->fetch();
    $stmt->close();

    if ($invoice_file) {
        // Delete the invoice record from the database
        $stmt = $conn->prepare("DELETE FROM project_invoices WHERE id = ?");
        $stmt->bind_param("i", $invoice_id);

        if ($stmt->execute()) {
            // Delete the invoice file from the server
            if (file_exists($invoice_file)) {
                unlink($invoice_file);
            }
            $success_message = "Invoice deleted successfully.";
        } else {
            $error_message = "Error deleting invoice: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Invoice not found.";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Process the uploaded invoice
    $project_id = intval($_POST['project_id']);
    $amount = floatval($_POST['amount']);
    $status = $_POST['status'] === 'Paid' ? 'Paid' : 'Open'; // Ensure only 'Paid' or 'Open'

    // Calculate issued date and due date
    $issued_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+30 days'));

    $upload_dir = 'uploads/invoices/';

    // Ensure the upload directory exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] == UPLOAD_ERR_OK) {
        $invoice_name = basename($_FILES['invoice_file']['name']);
        $invoice_path = $upload_dir . time() . '_' . $invoice_name;

        if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $invoice_path)) {
            // Save invoice information to the database
            $servername = "localhost";
            $db_username = "SolterraSolutions";
            $db_password = "CompanyAdmin!";
            $dbname = "solterra_portal";

            $conn = new mysqli($servername, $db_username, $db_password, $dbname);
            if ($conn->connect_error) {
                die("Connection failed: " . $conn->connect_error);
            }

            $stmt = $conn->prepare("INSERT INTO project_invoices (project_id, amount, status, issued_date, due_date, invoice_file) 
                                    VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("idssss", $project_id, $amount, $status, $issued_date, $due_date, $invoice_path);

            if ($stmt->execute()) {
                $success_message = "Invoice uploaded successfully.";
            } else {
                $error_message = "Error saving invoice: " . $stmt->error;
            }

            $stmt->close();
            // CHANGED: Removed $conn->close(); so the connection remains open for later queries.
            // $conn->close();  <-- This line caused the mysqli object to be closed prematurely
        } else {
            $error_message = "Failed to upload the invoice file.";
        }
    } else {
        $error_message = "No file uploaded or there was an upload error.";
    }
}

// Fetch projects
$stmt = $conn->prepare("SELECT id, project_name FROM projects");
$stmt->execute();
$projects_result = $stmt->get_result();
$stmt->close();

// Collect unique values for filtering
$usernames = [];
$project_names = [];
$statuses = [];

// Fetch invoices with related project and user information
$sql = "SELECT pi.*, p.project_name, u.username,
        CASE 
            WHEN pi.status = 'Paid' THEN 0
            WHEN DATEDIFF(CURDATE(), pi.due_date) > 0 THEN DATEDIFF(CURDATE(), pi.due_date)
            ELSE NULL
        END AS days_past_due
        FROM project_invoices pi
        JOIN projects p ON pi.project_id = p.id
        JOIN users u ON p.user_id = u.id
        ORDER BY pi.issued_date DESC";

$result = $conn->query($sql);

$invoices = [];
if ($result && $result->num_rows > 0) {
    while ($invoice = $result->fetch_assoc()) {
        $invoices[] = $invoice;

        // Collect unique values for filtering
        $usernames[] = $invoice['username'];
        $project_names[] = $invoice['project_name'];
        $statuses[] = $invoice['status'];
    }
}

$usernames = array_unique($usernames);
$project_names = array_unique($project_names);
$statuses = array_unique($statuses);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- existing head content -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Invoices</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Additional styles */
        body {
            font-family: 'Poppins', sans-serif;
        }
        .success-message, .error-message {
            text-align: center;
            margin-top: 15px;
        }
        .success-message {
            color: green;
        }
        .error-message {
            color: red;
        }
        /* Styles for the Add Invoice button */
        #add-invoice-button {
            display: inline-block;
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin: 20px auto;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
        }
        #add-invoice-button:hover {
            background-color: #293E4C;
        }
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            padding-top: 60px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 600px;
            border-radius: 8px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            margin-top: -10px;
        }
        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="file"],
        select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        button[type="submit"] {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin: 20px 0 0 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button[type="submit"]:hover {
            background-color: #293E4C;
        }
        .status-past-due {
            color: red;
            font-weight: bold;
        }
        /* Styles for the sorting and filtering dropdown */
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
        /* Styles for the filter checkboxes */
        .filter-checkbox {
            margin-right: 5px;
        }
        /* Controls container */
        .controls-container {
            text-align: right;
            margin-bottom: 20px;
        }
        .controls-container input[type="text"] {
            padding: 8px;
            width: 200px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
            }
            .controls-container {
                text-align: center;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
<h1>Invoices</h1>
    <?php
    if (isset($error_message) && $error_message != '') {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    if (isset($success_message) && $success_message != '') {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    ?>

    <button id="add-invoice-button">Add Invoice</button>

    <!-- The Modal -->
    <div id="addInvoiceModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Add Invoice</h2>
            <form id="add-invoice-form" action="" method="post" enctype="multipart/form-data">
                <label for="project_id">Select Project:</label>
                <select name="project_id" id="project_id" required>
                    <option value="">--Select Project--</option>
                    <?php foreach ($projects_result as $project): ?>
                        <option value="<?php echo $project['id']; ?>">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="amount">Amount:</label>
                <input type="number" name="amount" id="amount" step="0.01" required>

                <label for="status">Status:</label>
                <select name="status" id="status" required>
                    <option value="Open">Open</option>
                    <option value="Paid">Paid</option>
                </select>

                <label for="invoice_file">Select Invoice File:</label>
                <input type="file" name="invoice_file" id="invoice_file" accept=".pdf,.doc,.docx,.xls,.xlsx" required>

                <button type="submit">Submit</button>
            </form>
        </div>
    </div>

    <!-- Controls -->
    <div class="controls-container">
        <!-- Search Bar -->
        <input type="text" id="search-input" placeholder="Search..." onkeyup="searchTable()">
    </div>

    <table id="invoices-table">
        <thead>
            <tr>
                <th>
                    Username
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(0, 'string', 'asc'); return false;">Sort A-Z</a>
                            <a href="#" onclick="sortTable(0, 'string', 'desc'); return false;">Sort Z-A</a>
                            <hr>
                            <!-- Filter options -->
                            <div>
                                <label><input type="checkbox" class="filter-checkbox" data-column="0" value="all" checked> Select All</label>
                            </div>
                            <?php foreach ($usernames as $username): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="0"
                                               value="<?php echo htmlspecialchars($username); ?>" checked>
                                        <?php echo htmlspecialchars($username); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </th>
                <th>
                    Project Name
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(1, 'string', 'asc'); return false;">Sort A-Z</a>
                            <a href="#" onclick="sortTable(1, 'string', 'desc'); return false;">Sort Z-A</a>
                            <hr>
                            <!-- Filter options -->
                            <div>
                                <label><input type="checkbox" class="filter-checkbox" data-column="1" value="all" checked> Select All</label>
                            </div>
                            <?php foreach ($project_names as $project_name): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="1"
                                               value="<?php echo htmlspecialchars($project_name); ?>" checked>
                                        <?php echo htmlspecialchars($project_name); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </th>
                <th>
                    Issued Date
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(2, 'date', 'asc'); return false;">Sort Ascending</a>
                            <a href="#" onclick="sortTable(2, 'date', 'desc'); return false;">Sort Descending</a>
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
                            <a href="#" onclick="sortTable(4, 'num', 'asc'); return false;">Sort Ascending</a>
                            <a href="#" onclick="sortTable(4, 'num', 'desc'); return false;">Sort Descending</a>
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
                            <!-- Filter options -->
                            <div>
                                <label><input type="checkbox" class="filter-checkbox" data-column="5" value="all" checked> Select All</label>
                            </div>
                            <?php foreach ($statuses as $status): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="5"
                                               value="<?php echo htmlspecialchars($status); ?>" checked>
                                        <?php echo htmlspecialchars($status); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </th>
                <th>
                    Past Due (Days)
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(6, 'num', 'asc'); return false;">Sort Ascending</a>
                            <a href="#" onclick="sortTable(6, 'num', 'desc'); return false;">Sort Descending</a>
                        </div>
                    </div>
                </th>
                <th>Invoice PDF</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($invoices)): ?>
                <?php foreach ($invoices as $invoice): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($invoice['username']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['project_name']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['issued_date']); ?></td>
                        <td><?php echo htmlspecialchars($invoice['due_date']); ?></td>
                        <td><?php echo '$' . number_format($invoice['amount'], 2); ?></td>
                        <td>
                            <?php
                            $status = $invoice['status'];
                            $days_past_due = $invoice['days_past_due'];

                            if ($status == 'Open' && $days_past_due > 0) {
                                echo '<span class="status-past-due">Past Due</span>';
                            } else {
                                echo htmlspecialchars($status);
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ($days_past_due > 0) {
                                echo $days_past_due;
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($invoice['invoice_file'])): ?>
                                <a href="<?php echo htmlspecialchars($invoice['invoice_file']); ?>" target="_blank">View Invoice</a>
                            <?php else: ?>
                                No File
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edit_invoice?id=<?php echo $invoice['id']; ?>">Edit</a> |
                            <a href="add_invoice?action=delete&id=<?php echo $invoice['id']; ?>" 
                               onclick="return confirm('Are you sure you want to delete this invoice?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="9">No invoices found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</main>

<!-- JavaScript for Modal, Sorting, Filtering, and Search -->
<script>
    // Modal functionality
    var modal = document.getElementById("addInvoiceModal");
    var btn = document.getElementById("add-invoice-button");
    var span = document.getElementsByClassName("close")[0];

    btn.onclick = function() {
        modal.style.display = "block";
    };
    span.onclick = function() {
        modal.style.display = "none";
    };
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    };

    // Sorting, Filtering, and Search Functions
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

    // Filtering code
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
                    selectAllCheckbox.checked = allChecked;
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

            if (column.selectAllCheckbox.checked) {
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
            var allChecked = column.checkboxes.every(function(checkbox) {
                return checkbox.checked;
            });
            column.selectAllCheckbox.checked = allChecked;
        }

        // Now apply the filters
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var showRow = true;
            for (var columnIndex in filters) {
                var selectedValues = filters[columnIndex];
                if (selectedValues !== null) { 
                    var cellText = row.cells[parseInt(columnIndex)].innerText.toLowerCase();
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
