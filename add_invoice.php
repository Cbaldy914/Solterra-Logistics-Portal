<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is a global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: login");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize messages
$success_message = '';
$error_message   = '';

// Handle deletion of invoice
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $invoice_id = intval($_GET['id']);

    // Fetch the invoice to get the file path
    $stmt = $conn->prepare("SELECT invoice_file FROM project_invoices WHERE id = ?");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $stmt->bind_result($invoice_file);
    $stmt->fetch();
    $stmt->close();

    if ($invoice_file) {
        // Delete the invoice record
        $stmt = $conn->prepare("DELETE FROM project_invoices WHERE id = ?");
        $stmt->bind_param("i", $invoice_id);
        if ($stmt->execute()) {
            // Remove the physical file
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

// Handle adding a new invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = intval($_POST['project_id']);
    $amount     = floatval($_POST['amount']);
    $status     = ($_POST['status'] === 'Paid') ? 'Paid' : 'Open';

    // Basic date logic
    $issued_date = date('Y-m-d');
    $due_date    = date('Y-m-d', strtotime('+30 days'));

    $upload_dir = 'uploads/invoices/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $invoice_name = basename($_FILES['invoice_file']['name']);
        $invoice_path = $upload_dir . time() . '_' . $invoice_name;

        if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $invoice_path)) {
            // Insert record into DB
            $stmtAdd = $conn->prepare("
                INSERT INTO project_invoices (
                    project_id, amount, status, issued_date, due_date, invoice_file
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtAdd->bind_param("idssss", 
                $project_id, $amount, $status, $issued_date, $due_date, $invoice_path
            );
            if ($stmtAdd->execute()) {
                $success_message = "Invoice uploaded successfully.";
            } else {
                $error_message = "Error saving invoice: " . $stmtAdd->error;
            }
            $stmtAdd->close();
        } else {
            $error_message = "Failed to upload the invoice file.";
        }
    } else {
        $error_message = "No file uploaded or there was an upload error.";
    }
}

// Fetch list of projects to select from
$stmtProj = $conn->prepare("
    SELECT id, project_name 
      FROM projects
");
$stmtProj->execute();
$projects_result = $stmtProj->get_result();
$stmtProj->close();

// Collect unique values for filtering
$account_names = [];
$project_names = [];
$statuses      = [];

// Fetch all invoices, but now join `customer_accounts c` instead of `users`.
$sql = "
    SELECT 
        pi.*, 
        p.project_name, 
        c.name AS account_name,
        CASE 
            WHEN pi.status = 'Paid' THEN 0
            WHEN DATEDIFF(CURDATE(), pi.due_date) > 0 THEN DATEDIFF(CURDATE(), pi.due_date)
            ELSE NULL
        END AS days_past_due
    FROM project_invoices pi
    JOIN projects p 
        ON pi.project_id = p.id
    JOIN customer_accounts c 
        ON p.account_id = c.id
    ORDER BY pi.issued_date DESC
";
$result = $conn->query($sql);

$invoices = [];
if ($result && $result->num_rows > 0) {
    while ($invoice = $result->fetch_assoc()) {
        $invoices[] = $invoice;
        // For filtering
        $account_names[] = $invoice['account_name'];
        $project_names[] = $invoice['project_name'];
        $statuses[]      = $invoice['status'];
    }
}

$account_names = array_unique($account_names);
$project_names = array_unique($project_names);
$statuses      = array_unique($statuses);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .success-message, .error-message {
            text-align: center;
            margin-top: 15px;
        }
        .success-message { color: green; }
        .error-message { color: red; }

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
            left: 0; top: 0;
            width: 100%; height: 100%;
            padding-top: 60px;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe;
            margin: auto; padding: 20px;
            border: 1px solid #888;
            width: 90%; max-width: 600px;
            border-radius: 8px;
        }
        .close {
            color: #aaa; float: right;
            font-size: 28px; font-weight: bold;
            margin-top: -10px;
        }
        .close:hover, .close:focus {
            color: black; text-decoration: none; cursor: pointer;
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
        .status-past-due { color: red; font-weight: bold; }
        .sort-dropdown {
            position: relative; display: inline-block; cursor: pointer;
        }
        .sort-dropdown-content {
            display: none;
            position: absolute; right: 0;
            background-color: #f9f9f9; min-width: 200px; max-height: 300px;
            overflow-y: auto; box-shadow: 0 8px 16px rgba(0,0,0,0.2); z-index: 1;
        }
        .sort-dropdown-content a, .sort-dropdown-content div {
            color: black; padding: 8px 12px;
            text-decoration: none; display: block;
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
        .filter-checkbox { margin-right: 5px; }
        .controls-container { text-align: right; margin-bottom: 20px; }
        .controls-container input[type="text"] {
            padding: 8px; width: 200px;
            border-radius: 4px; border: 1px solid #ccc;
        }
        @media (max-width: 768px) {
            .modal-content { width: 95%; }
            .controls-container { text-align: center; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Invoices']); ?>
    
    <h1>Invoices</h1>
    <?php if ($error_message !== ''): ?>
        <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>
    <?php if ($success_message !== ''): ?>
        <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>

    <button id="add-invoice-button">Add Invoice</button>

    <!-- The Modal for adding a new invoice -->
    <div id="addInvoiceModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Add Invoice</h2>
            <form id="add-invoice-form" action="" method="post" enctype="multipart/form-data">
                <label for="project_id">Select Project:</label>
                <select name="project_id" id="project_id" required>
                    <option value="">--Select Project--</option>
                    <?php foreach ($projects_result as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>">
                            <?php echo htmlspecialchars($proj['project_name']); ?>
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

    <!-- Controls for search & filtering -->
    <div class="controls-container">
        <input type="text" id="search-input" placeholder="Search..." onkeyup="searchTable()">
    </div>

    <table id="invoices-table">
        <thead>
            <tr>
                <th>
                    Account Name
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(0, 'string', 'asc'); return false;">Sort A-Z</a>
                            <a href="#" onclick="sortTable(0, 'string', 'desc'); return false;">Sort Z-A</a>
                            <hr>
                            <div>
                                <label>
                                    <input type="checkbox" class="filter-checkbox" data-column="0" value="all" checked>
                                    Select All
                                </label>
                            </div>
                            <?php foreach ($account_names as $accName): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="0"
                                               value="<?php echo htmlspecialchars($accName); ?>" checked>
                                        <?php echo htmlspecialchars($accName); ?>
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
                            <div>
                                <label>
                                    <input type="checkbox" class="filter-checkbox" data-column="1" value="all" checked>
                                    Select All
                                </label>
                            </div>
                            <?php foreach ($project_names as $pName): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="1"
                                               value="<?php echo htmlspecialchars($pName); ?>" checked>
                                        <?php echo htmlspecialchars($pName); ?>
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
                            <a href="#" onclick="sortTable(2, 'date', 'asc'); return false;">Ascending</a>
                            <a href="#" onclick="sortTable(2, 'date', 'desc'); return false;">Descending</a>
                        </div>
                    </div>
                </th>
                <th>
                    Due Date
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(3, 'date', 'asc'); return false;">Ascending</a>
                            <a href="#" onclick="sortTable(3, 'date', 'desc'); return false;">Descending</a>
                        </div>
                    </div>
                </th>
                <th>
                    Amount
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(4, 'num', 'asc'); return false;">Ascending</a>
                            <a href="#" onclick="sortTable(4, 'num', 'desc'); return false;">Descending</a>
                        </div>
                    </div>
                </th>
                <th>
                    Status
                    <div class="sort-dropdown">
                        <span class="sort-icon">&#9660;</span>
                        <div class="sort-dropdown-content">
                            <a href="#" onclick="sortTable(5, 'string', 'asc'); return false;">A-Z</a>
                            <a href="#" onclick="sortTable(5, 'string', 'desc'); return false;">Z-A</a>
                            <hr>
                            <div>
                                <label>
                                    <input type="checkbox" class="filter-checkbox" data-column="5" value="all" checked>
                                    Select All
                                </label>
                            </div>
                            <?php foreach ($statuses as $st): ?>
                                <div>
                                    <label>
                                        <input type="checkbox" class="filter-checkbox" data-column="5"
                                               value="<?php echo htmlspecialchars($st); ?>" checked>
                                        <?php echo htmlspecialchars($st); ?>
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
                            <a href="#" onclick="sortTable(6, 'num', 'asc'); return false;">Ascending</a>
                            <a href="#" onclick="sortTable(6, 'num', 'desc'); return false;">Descending</a>
                        </div>
                    </div>
                </th>
                <th>Invoice PDF</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($invoices)): ?>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?php echo htmlspecialchars($inv['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['project_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['issued_date']); ?></td>
                    <td><?php echo htmlspecialchars($inv['due_date']); ?></td>
                    <td><?php echo '$' . number_format($inv['amount'], 2); ?></td>
                    <td>
                        <?php
                            $status       = $inv['status'];
                            $days_past_due= $inv['days_past_due'];
                            if ($status === 'Open' && $days_past_due > 0) {
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
                        <?php if (!empty($inv['invoice_file'])): ?>
                            <a href="<?php echo htmlspecialchars($inv['invoice_file']); ?>" target="_blank">
                                View Invoice
                            </a>
                        <?php else: ?>
                            No File
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="edit_invoice?id=<?php echo $inv['id']; ?>">Edit</a> |
                        <a href="add_invoice?action=delete&id=<?php echo $inv['id']; ?>"
                           onclick="return confirm('Are you sure you want to delete this invoice?');">
                           Delete
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="10">No invoices found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</main>

<!-- JavaScript for Modal, Sorting, Filtering, and Search -->
<script>
    // Modal for adding invoice
    var modal = document.getElementById("addInvoiceModal");
    var btn   = document.getElementById("add-invoice-button");
    var span  = document.getElementsByClassName("close")[0];

    btn.onclick = function() { modal.style.display = "block"; };
    span.onclick = function() { modal.style.display = "none"; };
    window.onclick = function(event) {
        if (event.target === modal) {
            modal.style.display = "none";
        }
    };

    // Basic table search
    function searchTable() {
        var input  = document.getElementById("search-input");
        var filter = input.value.toLowerCase();
        var table  = document.getElementById("invoices-table");
        var tr     = table.getElementsByTagName("tr");
        for (var i = 1; i < tr.length; i++) {
            // If row hidden by filters, skip
            if (tr[i].style.display === 'none') continue;
            tr[i].style.display = "none";
            var tds = tr[i].getElementsByTagName("td");
            var visible = false;
            for (var j = 0; j < tds.length; j++) {
                if (tds[j]) {
                    var txt = tds[j].textContent || tds[j].innerText;
                    if (txt.toLowerCase().indexOf(filter) > -1) {
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

    // Sorting
    function sortTable(columnIndex, type, order) {
        var table = document.getElementById("invoices-table");
        var tbody = table.tBodies[0];
        var rows  = Array.from(tbody.rows);

        var compare;
        switch (type) {
            case 'num':
                compare = function(a, b) {
                    var aValue = parseFloat(a.cells[columnIndex].innerText) || 0;
                    var bValue = parseFloat(b.cells[columnIndex].innerText) || 0;
                    return (order === 'asc') ? aValue - bValue : bValue - aValue;
                };
                break;
            case 'string':
                compare = function(a, b) {
                    var aValue = (a.cells[columnIndex].innerText).toLowerCase();
                    var bValue = (b.cells[columnIndex].innerText).toLowerCase();
                    if (aValue < bValue) return (order === 'asc') ? -1 : 1;
                    if (aValue > bValue) return (order === 'asc') ? 1 : -1;
                    return 0;
                };
                break;
            case 'date':
                compare = function(a, b) {
                    var aText = a.cells[columnIndex].innerText.trim() || '1970-01-01';
                    var bText = b.cells[columnIndex].innerText.trim() || '1970-01-01';
                    var aDate = new Date(aText);
                    var bDate = new Date(bText);
                    return (order === 'asc') ? aDate - bDate : bDate - aDate;
                };
                break;
            default:
                return;
        }

        rows.sort(compare);

        while (tbody.firstChild) {
            tbody.removeChild(tbody.firstChild);
        }
        rows.forEach(function(r) { tbody.appendChild(r); });
        applyFilters();
    }

    // Filtering
    document.addEventListener('DOMContentLoaded', function() {
        var filterCheckboxes = document.querySelectorAll('.filter-checkbox');
        filterCheckboxes.forEach(function(checkbox) {
            // Prevent dropdown from closing
            checkbox.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            checkbox.addEventListener('change', function() {
                var colIndex = parseInt(this.getAttribute('data-column'));
                var val      = this.value;
                if (val === 'all') {
                    var checked   = this.checked;
                    var siblings  = document.querySelectorAll('.filter-checkbox[data-column="' + colIndex + '"]');
                    siblings.forEach(function(sib) { sib.checked = checked; });
                } else {
                    var allCh     = true;
                    var siblings2 = document.querySelectorAll('.filter-checkbox[data-column="' + colIndex + '"]:not([value="all"])');
                    siblings2.forEach(function(sib) {
                        if (!sib.checked) allCh = false;
                    });
                    var selectAll = document.querySelector('.filter-checkbox[data-column="' + colIndex + '"][value="all"]');
                    selectAll.checked = allCh;
                }
                applyFilters();
            });
        });

        // Close dropdown on outside clicks
        var sortIcons = document.querySelectorAll('.sort-icon');
        sortIcons.forEach(function(icon) {
            icon.addEventListener('click', function(e) {
                e.stopPropagation();
                closeAllDropdowns();
                icon.parentElement.classList.toggle('open');
            });
        });
        document.addEventListener('click', function(e) {
            var inside = e.target.closest('.sort-dropdown-content');
            if (!inside) closeAllDropdowns();
        });
        var dropdowns = document.querySelectorAll('.sort-dropdown-content');
        dropdowns.forEach(function(dd) {
            dd.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    });

    function closeAllDropdowns() {
        var dd = document.querySelectorAll('.sort-dropdown');
        dd.forEach(function(d) { d.classList.remove('open'); });
    }

    function applyFilters() {
        var table = document.getElementById('invoices-table');
        var tbody = table.tBodies[0];
        var rows  = tbody.getElementsByTagName('tr');

        var filters = {};
        var columns = {};

        var checkboxes = document.querySelectorAll('.filter-checkbox');
        checkboxes.forEach(function(checkbox) {
            var cIndex = parseInt(checkbox.getAttribute('data-column'));
            if (!columns[cIndex]) {
                columns[cIndex] = { checkboxes: [], selectAllCheckbox: null };
            }
            if (checkbox.value === 'all') {
                columns[cIndex].selectAllCheckbox = checkbox;
            } else {
                columns[cIndex].checkboxes.push(checkbox);
            }
        });

        for (var col in columns) {
            var colObj = columns[col];
            var selVals = [];
            if (colObj.selectAllCheckbox.checked) {
                filters[col] = null;
                colObj.checkboxes.forEach(function(cb) { cb.checked = true; });
            } else {
                colObj.checkboxes.forEach(function(cb) {
                    if (cb.checked) selVals.push(cb.value.toLowerCase());
                });
                filters[col] = selVals;
            }
            var allCh = colObj.checkboxes.every(function(cb) { return cb.checked; });
            colObj.selectAllCheckbox.checked = allCh;
        }

        for (var i=0; i<rows.length; i++) {
            var row = rows[i];
            var show = true;
            for (var c in filters) {
                var fVals = filters[c];
                if (fVals !== null) {
                    var cellText = row.cells[parseInt(c)].innerText.toLowerCase();
                    if (fVals.indexOf(cellText) === -1) {
                        show = false;
                        break;
                    }
                }
            }
            row.style.display = show ? '' : 'none';
        }
        // Re-apply search after filtering
        searchTable();
    }
</script>
</body>
</html>
