<?php
session_name("logistics_session");
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}
// Rest of your admin dashboard code

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>

<?php 

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch ALL projects
$sqlProj = "
    SELECT id, project_name, project_address, image_url, estimated_completion_date
    FROM projects
    ORDER BY id ASC
";
$stmt = $conn->prepare($sqlProj);

// Check if prepare() failed
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error); 
}

$stmt->execute();
$result = $stmt->get_result();

// Check if get_result() failed
if ($result === false) {
    die("Error getting result set: " . $stmt->error);
}

$projects = [];
while ($row = $result->fetch_assoc()) {
    $project_id = $row['id'];
    $project = $row;

    // ---- Fetch wattage orders
    $stmt_wattage_orders = $conn->prepare("
        SELECT wattage, total_order
        FROM project_wattage_orders
        WHERE project_id = ?
    ");
    if($stmt_wattage_orders === false) die("Prepare failed: (wattage) " . $conn->error);
    $stmt_wattage_orders->bind_param("i", $project_id);
    $stmt_wattage_orders->execute();
    $wattage_orders_result = $stmt_wattage_orders->get_result();
    $stmt_wattage_orders->close();

    $total_mws = 0;
    $total_order_quantity = 0;
    while ($wattage_row = $wattage_orders_result->fetch_assoc()) {
        $wattage = (float)$wattage_row['wattage'];
        $torder  = (int)$wattage_row['total_order'];
        $total_order_quantity += $torder;
        $total_mws += ($wattage * $torder) / 1000000; // Convert to MW
    }
    $project['project_size'] = $total_mws;

    // ---- Modules Delivered
    $stmt_delivered = $conn->prepare("
        SELECT SUM(quantity) AS total_delivered
        FROM deliveries
        WHERE project_id = ? AND status_of_delivery = 'Delivered'
    ");
     if($stmt_delivered === false) die("Prepare failed: (delivered) " . $conn->error);
    $stmt_delivered->bind_param("i", $project_id);
    $stmt_delivered->execute();
    $stmt_delivered->bind_result($total_delivered);
    $stmt_delivered->fetch();
    $stmt_delivered->close();
    $total_delivered = $total_delivered ? $total_delivered : 0;

    // ---- Delivery Completion
    $module_delivery_completion = 0;
    if ($total_order_quantity > 0) {
        $module_delivery_completion = ($total_delivered / $total_order_quantity) * 100;
    }

    // ---- In Warehouse
    $stmt_in_storage = $conn->prepare("
        SELECT SUM(quantity) AS total_in_storage
        FROM deliveries
        WHERE project_id = ? AND status_of_delivery = 'In Warehouse'
    ");
     if($stmt_in_storage === false) die("Prepare failed: (storage) " . $conn->error);
    $stmt_in_storage->bind_param("i", $project_id);
    $stmt_in_storage->execute();
    $stmt_in_storage->bind_result($total_in_storage);
    $stmt_in_storage->fetch();
    $stmt_in_storage->close();
    $total_in_storage = $total_in_storage ? $total_in_storage : 0;

    $modules_in_storage = 0;
    if ($total_order_quantity > 0) {
        $modules_in_storage = ($total_in_storage / $total_order_quantity) * 100;
    }

    // Add computed values
    $project['module_delivery_completion'] = round($module_delivery_completion, 2);
    $project['modules_in_storage']         = round($modules_in_storage, 2);

    $projects[] = $project;
}
$stmt->close();

// Fetch ALL Modules
$sqlModules = "
    SELECT um.id, um.account_id, um.vendor_name, um.initial_location, c.name as account_name, p.project_name
    FROM modules um
    JOIN customer_accounts c ON um.account_id = c.id
    LEFT JOIN projects p ON um.project_id = p.id
    ORDER BY c.name ASC, um.vendor_name ASC
";
$stmt_modules = $conn->prepare($sqlModules);
if($stmt_modules === false) die("Prepare failed: (modules) " . $conn->error);
$stmt_modules->execute();
$result_modules = $stmt_modules->get_result();

$modules_data = [];
$stmt_items = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC");
if (!$stmt_items) die("Prepare failed: (module items) " . $conn->error);

while ($batch = $result_modules->fetch_assoc()) {
    $batch_id = $batch['id'];
    $batch['items'] = [];
    $batch['total_quantity'] = 0;
    $wattages = [];

    $stmt_items->bind_param("i", $batch_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    while ($item = $result_items->fetch_assoc()) {
        $batch['items'][] = $item;
        $batch['total_quantity'] += $item['quantity'];
        $wattages[] = (int)$item['wattage']; // Cast as int
    }

    // Calculate wattage range
    if (count($wattages) > 0) {
        $min_w = min($wattages);
        $max_w = max($wattages);
        $batch['wattage_range'] = ($min_w == $max_w) ? $min_w . 'W' : $min_w . 'W - ' . $max_w . 'W';
    } else {
        $batch['wattage_range'] = 'N/A';
    }
    
    // Store details as JSON for modal
    $batch['details_json'] = htmlspecialchars(json_encode($batch['items']), ENT_QUOTES, 'UTF-8');

    $modules_data[] = $batch;
}
$stmt_items->close();
$stmt_modules->close();

// Close DB
$conn->close();
?>
<main>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)!</h1>
    <p>This is the global admin dashboard.</p>

    <h2>Active Projects:</h2>
    <div class="projects-container">
        <?php if (!empty($projects)): ?>
             <?php 
            $target_page = 'project_overview.php'; // Make sure this page exists or adjust as needed
            ?>
            <?php foreach ($projects as $project): ?>
                <?php
                // Format completion date if not null
                $estimated_completion_date_display = 'N/A';
                if (!empty($project['estimated_completion_date'])) {
                    try {
                       $dateObj = new DateTime($project['estimated_completion_date']);
                       $estimated_completion_date_display = $dateObj->format('F j, Y');
                    } catch (Exception $e) {
                        // Handle potential date format issues gracefully
                         $estimated_completion_date_display = 'Invalid Date';
                    }
                }
                ?>
                <div class="project-item">
                    <h3>
                        <a href="<?php echo $target_page; ?>?id=<?php echo $project['id']; ?>">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </a>
                    </h3>
                    <div class="project-image">
                        <a href="<?php echo $target_page; ?>?id=<?php echo $project['id']; ?>">
                             <img src="<?php echo htmlspecialchars(!empty($project['image_url']) ? $project['image_url'] : 'pictures/default_project_image.png'); ?>" alt="Project Image" style="width:100%; height:auto; max-height: 150px; object-fit: cover;">
                        </a>
                    </div>
                    <div class="project-details">
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($project['project_address']); ?></p>
                        <p><strong>Project Size:</strong> <?php echo htmlspecialchars(number_format($project['project_size'], 2)); ?> MW</p>
                        <p><strong>Module Delivery Completion:</strong> <?php echo htmlspecialchars($project['module_delivery_completion']); ?>%</p>
                        <p><strong>Estimated Completion Date:</strong> <?php echo htmlspecialchars($estimated_completion_date_display); ?></p>
                        <p><strong>Percent of Modules in Storage:</strong> <?php echo htmlspecialchars($project['modules_in_storage']); ?>%</p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>No active projects found across all accounts.</p>
        <?php endif; ?>
    </div>

    <!-- Modules Section -->
    <h2 style="margin-top: 40px;">Module Batches:</h2>
    <?php if (!empty($modules_data)): ?>
        <table>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Vendor</th>
                    <th>Assigned Project</th>
                    <th>Wattage Range</th>
                    <th>Total Quantity</th>
                    <th>Initial Location</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules_data as $batch): ?>
                    <tr style="cursor: pointer;" 
                        onclick="openDetailsModal(<?php echo $batch['id']; ?>, '<?php echo htmlspecialchars($batch['vendor_name'], ENT_QUOTES, 'UTF-8'); ?>', this.dataset.details)" 
                        data-details='<?php echo $batch['details_json']; ?>'>
                        <td><?php echo htmlspecialchars($batch['account_name']); ?></td>
                        <td><?php echo htmlspecialchars($batch['vendor_name']); ?></td>
                        <td>
                            <?php 
                              if (!empty($batch['project_id']) && !empty($batch['project_name'])) {
                                  echo htmlspecialchars($batch['project_name']); 
                              } else {
                                  echo "<em>Unassigned</em>";
                              }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($batch['wattage_range']); ?></td>
                        <td><?php echo number_format($batch['total_quantity']); ?></td>
                        <td><?php echo htmlspecialchars($batch['initial_location']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No module batches found.</p>
    <?php endif; ?>
</main>

<!-- Module Details Modal -->
<div id="moduleDetailsModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close-button" onclick="closeDetailsModal()">&times;</span>
        <h2 id="modalTitle">Module Batch Details</h2>
        <div id="modalBody">
            <!-- Details will be populated here -->
        </div>
        <div style="text-align: right; margin-top: 20px;">
            <button id="modalEditButton" class="action-button">Edit Batch</button>
        </div>
    </div>
</div>

<style>
/* Basic Modal Styling */
.modal {
  position: fixed; 
  z-index: 1000; 
  left: 0;
  top: 0;
  width: 100%; 
  height: 100%; 
  overflow: auto; 
  background-color: rgba(0,0,0,0.4); 
}
.modal-content {
  background-color: #fefefe;
  margin: 15% auto; 
  padding: 20px;
  border: 1px solid #888;
  width: 80%; 
  max-width: 500px;
  border-radius: 5px;
  position: relative;
}
.close-button {
  color: #aaa;
  position: absolute;
  top: 10px;
  right: 20px;
  font-size: 28px;
  font-weight: bold;
}
.close-button:hover,
.close-button:focus {
  color: black;
  text-decoration: none;
  cursor: pointer;
}

</style>

<script>
function openDetailsModal(batchId, vendorName, detailsJson) {
    const modal = document.getElementById('moduleDetailsModal');
    const title = document.getElementById('modalTitle');
    const body = document.getElementById('modalBody');
    const editButton = document.getElementById('modalEditButton');
    
    title.textContent = vendorName + ' - Batch Details';
    body.innerHTML = ''; // Clear previous content

    try {
        const items = JSON.parse(detailsJson);
        if (items && items.length > 0) {
            let tableHTML = '<table><thead><tr><th>Wattage</th><th>Quantity</th></tr></thead><tbody>';
            items.forEach(item => {
                // Ensure wattage is treated as a whole number for display
                tableHTML += `<tr><td>${escapeHTML(parseInt(item.wattage))}W</td><td>${escapeHTML(item.quantity)}</td></tr>`; 
            });
            tableHTML += '</tbody></table>';
            body.innerHTML = tableHTML;
        } else {
            body.innerHTML = '<p>No specific items found for this batch.</p>';
        }
    } catch (e) {
        body.innerHTML = '<p>Error loading details.</p>';
        console.error("Error parsing details JSON:", e);
    }

    // Set up edit button link
    editButton.onclick = function() {
        window.location.href = `edit_unassigned_module.php?batch_id=${batchId}`;
    };

    modal.style.display = 'block';
}

function closeDetailsModal() {
    document.getElementById('moduleDetailsModal').style.display = 'none';
}

// Close modal if user clicks outside of it
window.onclick = function(event) {
    const modal = document.getElementById('moduleDetailsModal');
    if (event.target == modal) {
        closeDetailsModal();
    }
}

// Basic HTML escaping function
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>'"/]/g, function (s) {
        const entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;'
        };
        return entityMap[s];
    });
}
</script>

</body>
</html>
