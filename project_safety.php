<?php
session_name("logistics_session");
session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for a project_id parameter; if missing, exit.
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}
$project_id = (int)$_GET['project_id'];

// DB connection
$servername  = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname      = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Retrieve the project name for header display
$stmt_proj = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
$stmt_proj->bind_param("i", $project_id);
$stmt_proj->execute();
$stmt_proj->bind_result($project_name);
$stmt_proj->fetch();
$stmt_proj->close();

if (!$project_name) {
    die("Project not found.");
}

// Determine the time filter (default "all")
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';

// Use a reference date from GET if available; otherwise, use today's date
$ref_date = isset($_GET['ref_date']) ? $_GET['ref_date'] : date('Y-m-d');

$params = [];
$paramTypes = "";
if ($time_filter == "day") {
    // Filter for incidents on the specific day (ref_date)
    $dateCondition = " AND DATE(ss.created_at) = ?";
    $params[] = $ref_date;
    $paramTypes .= "s";
    $dateLabel = date('F j, Y', strtotime($ref_date)); // e.g., "February 4, 2025"
    $prev_date = date('Y-m-d', strtotime("$ref_date -1 day"));
    $next_date = date('Y-m-d', strtotime("$ref_date +1 day"));
} elseif ($time_filter == "week") {
    // Filter for incidents within the week containing ref_date
    $timestamp = strtotime($ref_date);
    $dayOfWeek = date('w', $timestamp); // 0 (Sunday) to 6
    $startOfWeek = date('Y-m-d', strtotime("-$dayOfWeek days", $timestamp));
    $endOfWeek = date('Y-m-d', strtotime("+" . (6 - $dayOfWeek) . " days", $timestamp));
    $dateCondition = " AND DATE(ss.created_at) BETWEEN ? AND ?";
    $params[] = $startOfWeek;
    $params[] = $endOfWeek;
    $paramTypes .= "ss";
    $dateLabel = date('M j', strtotime($startOfWeek)) . " - " . date('M j, Y', strtotime($endOfWeek));
    $prev_date = date('Y-m-d', strtotime("$startOfWeek -7 days"));
    $next_date = date('Y-m-d', strtotime("$startOfWeek +7 days"));
} elseif ($time_filter == "month") {
    // Filter for incidents within the month of ref_date
    $startOfMonth = date('Y-m-01', strtotime($ref_date));
    $endOfMonth = date('Y-m-t', strtotime($ref_date));
    $dateCondition = " AND DATE(ss.created_at) BETWEEN ? AND ?";
    $params[] = $startOfMonth;
    $params[] = $endOfMonth;
    $paramTypes .= "ss";
    $dateLabel = date('F Y', strtotime($ref_date)); // e.g., "February 2025"
    $prev_date = date('Y-m-d', strtotime("$startOfMonth -1 month"));
    $next_date = date('Y-m-d', strtotime("$startOfMonth +1 month"));
} else {
    // "all" – no date filtering
    $dateCondition = "";
    $dateLabel = "All Safety Incidents";
}

// If action=export_csv => output CSV and exit
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="safety_incidents_export.csv"');

    $out = fopen('php://output', 'w');
    // CSV header
    fputcsv($out, ['BOL#', 'Reported Driver', 'Notes', 'Date']);
    
    // Build export SQL query
    $sql_export = "
      SELECT
        ss.bol_number,
        ss.report_driver,
        ss.notes,
        ss.created_at
      FROM site_safety ss
      JOIN site_scheduling s ON ss.scheduling_id = s.id
      JOIN sites si ON s.site_id = si.id
      WHERE si.project_id = ? $dateCondition
      ORDER BY ss.created_at DESC
    ";
    $stmt = $conn->prepare($sql_export);
    if ($dateCondition) {
        $stmt->bind_param("i" . $paramTypes, $project_id, ...$params);
    } else {
        $stmt->bind_param("i", $project_id);
    }
    $stmt->execute();
    $res_exp = $stmt->get_result();
    while ($row_exp = $res_exp->fetch_assoc()) {
        $bol = $row_exp['bol_number'] ?? '';
        $driver = $row_exp['report_driver'] ?? '';
        $notes = $row_exp['notes'] ?? '';
        $date = formatDate($row_exp['created_at']);
        fputcsv($out, [$bol, $driver, $notes, $date]);
    }
    fclose($out);
    $stmt->close();
    $conn->close();
    exit();
}

// Build the main SQL query for safety incidents
$sql = "
SELECT
  ss.id AS safety_id,
  ss.bol_number,
  ss.report_driver,
  ss.notes,
  ss.pictures,
  ss.created_at,
  ss.updated_at
FROM site_safety ss
JOIN site_scheduling s ON ss.scheduling_id = s.id
JOIN sites si ON s.site_id = si.id
WHERE si.project_id = ? $dateCondition
ORDER BY ss.created_at DESC
";
$stmt = $conn->prepare($sql);
if ($dateCondition) {
    $stmt->bind_param("i" . $paramTypes, $project_id, ...$params);
} else {
    $stmt->bind_param("i", $project_id);
}
$stmt->execute();
$result = $stmt->get_result();

$safetyRows = [];
while ($row = $result->fetch_assoc()) {
    $safetyRows[] = $row;
}
$stmt->close();
$conn->close();

// Helper function to format dates
function formatDate($dtStr) {
    if (empty($dtStr) || $dtStr === '0000-00-00') {
        return '';
    }
    $dt = new DateTime($dtStr);
    return $dt->format('M j, Y');
}

// Compute metrics: total incidents and unique drivers
$total_incidents = count($safetyRows);
$driverSet = [];
foreach ($safetyRows as $row) {
    if (!empty($row['report_driver'])) {
        $driverSet[$row['report_driver']] = true;
    }
}
$total_drivers = count($driverSet);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Safety Details for <?php echo htmlspecialchars($project_name); ?></title>
  <link rel="stylesheet" href="portal.css">
  <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* Styles for metrics overview boxes */
    .metrics-overview {
      display: flex;
      gap: 20px;
      margin-bottom: 30px; /* increased margin */
      flex-wrap: wrap;
    }
    .metric-box {
      flex: 1;
      min-width: 200px;
      background-color: #f2f2f2;
      padding: 20px;
      text-align: center;
      border-radius: 8px;
    }
    .metric-box h3 {
      margin-bottom: 10px;
    }
    /* Time filter header styles */
    .time-filter-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 30px; /* increased margin from metrics */
      margin-bottom: 10px;
      flex-wrap: wrap;
    }
    .time-filters {
      display: flex;
      gap: 10px;
    }
    .time-filters a {
      text-decoration: none;
      padding: 6px 12px;
      background: #eee;
      border-radius: 4px;
      color: #333;
    }
    .time-filters a.active {
      background: #488C9A;
      color: #fff;
    }
    /* Date navigation styles with buttons */
    .date-navigation {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .nav-arrow {
      font-weight: bold;
      cursor: pointer;
    }
    .nav-arrow:focus {
      outline: none;
    }
    .date-label {
      font-weight: bold;
      font-size: 1.1em;
    }
    .export-container {
      text-align: right;
    }
    .export-link {
      display: inline-block;
      padding: 6px 12px;
      background: #488C9A;
      color: #fff;
      border-radius: 4px;
      text-decoration: none;
    }
    /* Table styling */
    .table-filters {
      margin-bottom: 20px;
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      gap: 20px;
    }
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }
    .filter-group label {
      font-weight: bold;
    }
    .filter-group select,
    .filter-group input {
      padding: 6px 8px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
    }
    table, th, td {
      border: 1px solid #ccc;
    }
    tr:hover {
      background: #f1f1f1;
    }
    .docs-icon {
      cursor: pointer;
    }
    .docs-dropdown {
      position: relative;
      display: inline-block;
    }
    .docs-dropdown-content {
      display: none;
      position: absolute;
      background: #fff;
      border: 1px solid #ccc;
      padding: 8px;
      min-width: 200px;
      z-index: 999;
      right: 0;
    }
    .docs-dropdown-content a {
      display: block;
      margin-bottom: 5px;
    }
    .docs-dropdown-content a:last-child {
      margin-bottom: 0;
    }
    .docs-dropdown-content.show {
      display: block;
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>
    <main>
    <a href="DDPm_overview?id=<?php echo $project_id; ?>" class="back-icon" style="margin:20px;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:24px;height:24px;">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>
    <div class="container">
      <h1>Safety Details for <?php echo htmlspecialchars($project_name); ?></h1>
      
      <!-- Metrics Overview Boxes (Safety Incidents first, then Drivers Reported) -->
      <div class="metrics-overview">
        <div class="metric-box">
          <h3>Safety Incidents</h3>
          <p><?php echo $total_incidents; ?></p>
        </div>
        <div class="metric-box">
          <h3>Drivers Reported</h3>
          <p><?php echo $total_drivers; ?></p>
        </div>
      </div>
      
      <!-- Time Filter Header with Navigation Buttons -->
      <div class="time-filter-header">
        <div class="time-filters">
          <a href="?project_id=<?php echo $project_id; ?>&time_filter=all" class="<?php echo ($time_filter=='all') ? 'active' : ''; ?>">All</a>
          <a href="?project_id=<?php echo $project_id; ?>&time_filter=day" class="<?php echo ($time_filter=='day') ? 'active' : ''; ?>">Day</a>
          <a href="?project_id=<?php echo $project_id; ?>&time_filter=week" class="<?php echo ($time_filter=='week') ? 'active' : ''; ?>">Week</a>
          <a href="?project_id=<?php echo $project_id; ?>&time_filter=month" class="<?php echo ($time_filter=='month') ? 'active' : ''; ?>">Month</a>
        </div>
        <div class="date-navigation">
          <?php if ($time_filter != "all") : ?>
            <button type="button" class="nav-arrow" onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $prev_date; ?>'">&larr;</button>
          <?php endif; ?>
          <span class="date-label"><?php echo $dateLabel; ?></span>
          <?php if ($time_filter != "all") : ?>
            <button type="button" class="nav-arrow" onclick="window.location.href='?project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $next_date; ?>'">&rarr;</button>
          <?php endif; ?>
        </div>
        <div class="export-container">
          <a href="?action=export_csv&project_id=<?php echo $project_id; ?>&time_filter=<?php echo $time_filter; ?>&ref_date=<?php echo $ref_date; ?>" class="export-link">Export to CSV</a>
        </div>
      </div>
      
      <!-- Safety Incidents Table -->
      <table id="safetyTable">
        <thead>
          <tr>
            <th>BOL#</th>
            <th>Reported Driver</th>
            <th>Notes</th>
            <th>Date</th>
            <th>Documents</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($safetyRows)): ?>
            <?php foreach ($safetyRows as $row): 
              $bol = $row['bol_number'] ?? '';
              $driver = $row['report_driver'] ?? '';
              $notes = $row['notes'] ?? '';
              $date = formatDate($row['created_at']);
              // Prepare pictures (assumed stored as JSON)
              $picsArr = [];
              if (!empty($row['pictures'])) {
                  $tmp = json_decode($row['pictures'], true);
                  if (is_array($tmp)) {
                      $picsArr = $tmp;
                  }
              }
              $safetyId = (int)$row['safety_id'];
            ?>
            <tr>
              <td><?php echo htmlspecialchars($bol); ?></td>
              <td><?php echo htmlspecialchars($driver); ?></td>
              <td><?php echo htmlspecialchars($notes); ?></td>
              <td><?php echo htmlspecialchars($date); ?></td>
              <td>
                <?php if (!empty($picsArr)): ?>
                  <div class="docs-dropdown">
                    <span class="docs-icon" onclick="toggleDropdown(<?php echo $safetyId; ?>)">
                      <img src="pictures/folder_icon.jpg" alt="folder"> (<?php echo count($picsArr); ?>)
                    </span>
                    <div class="docs-dropdown-content" id="dropdown_<?php echo $safetyId; ?>">
                      <?php foreach ($picsArr as $imgPath):
                        $fileName = basename($imgPath);
                      ?>
                        <a href="<?php echo htmlspecialchars($imgPath); ?>" target="_blank">
                          <?php echo htmlspecialchars($fileName); ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <span style="color:#999;">-</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5">No safety incidents found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  
  <script>
    // Toggle dropdown for documents
    function toggleDropdown(safetyId) {
      var dd = document.getElementById('dropdown_' + safetyId);
      if (!dd) return;
      var allDropdowns = document.querySelectorAll('.docs-dropdown-content');
      allDropdowns.forEach(function(d) {
        if (d !== dd) {
          d.classList.remove('show');
        }
      });
      dd.classList.toggle('show');
    }
  </script>
</body>
</html>
