<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// -----------------------------------------------------------
// Database connection
// -----------------------------------------------------------
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user_id'];

// -----------------------------------------------------------
// Handle Loading a Scenario if ?id=xxx is passed
// -----------------------------------------------------------
$loaded_scenario_id = null;     // If set, user is editing that scenario
$loaded_scenario_data = null;   // JSON decoded scenario data
$loaded_scenario_name = null;

if (isset($_GET['id'])) {
    $loaded_scenario_id = intval($_GET['id']);
    // Load from DB, verifying user_id + filtering for app_type='optimization'
    $sql = "SELECT name, estimate_data
              FROM warehouse_estimates
             WHERE id=? AND user_id=? AND app_type='optimization'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $loaded_scenario_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $loaded_scenario_name = $row['name'];
        $loaded_scenario_data = json_decode($row['estimate_data'], true);
    } else {
        // Invalid or not found
        $loaded_scenario_id = null;
    }
    $stmt->close();
}

// -----------------------------------------------------------
// Handle Save (either new or overwrite)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_scenario') {
    // Gather posted data (JSON from hidden field)
    $scenario_json = $_POST['scenario_json'] ?? '';
    $scenario_name = $_POST['scenario_name'] ?? '';
    $scenario_id   = $_POST['scenario_id'] ?? '';

    if (empty($scenario_name)) {
        // Default name if none provided
        $scenario_name = "Untitled Scenario";
    }

    // Insert or update
    if ($scenario_id) {
        // Overwrite existing row
        $sql = "UPDATE warehouse_estimates
                   SET name=?, estimate_data=?, created_at=NOW(), app_type='optimization'
                 WHERE id=? AND user_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssii", $scenario_name, $scenario_json, $scenario_id, $user_id);
        $stmt->execute();
        $stmt->close();
        // Redirect to ?id=xxx so page reloads with loaded scenario
        header("Location: warehouse_optimization.php?id=" . intval($scenario_id));
        exit();
    } else {
        // Insert new
        $sql = "INSERT INTO warehouse_estimates
                (user_id, name, estimate_data, created_at, app_type)
                VALUES (?, ?, ?, NOW(), 'optimization')";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $user_id, $scenario_name, $scenario_json);
        $stmt->execute();
        $new_id = $stmt->insert_id;
        $stmt->close();
        // Redirect to new scenario
        header("Location: warehouse_optimization.php?id=" . intval($new_id));
        exit();
    }
}

// -----------------------------------------------------------
// Handle Delete
// -----------------------------------------------------------
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $sql = "DELETE FROM warehouse_estimates
             WHERE id=? AND user_id=? AND app_type='optimization'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $delete_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: warehouse_optimization.php");
    exit();
}

// -----------------------------------------------------------
// Fetch all saved scenarios to display in the "Saved Scenarios" table
// -----------------------------------------------------------
$saved_scenarios = [];
$sql = "SELECT id, name, estimate_data, created_at
          FROM warehouse_estimates
         WHERE user_id=? AND app_type='optimization'
      ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $saved_scenarios[] = $row;
}
$stmt->close();

// -----------------------------------------------------------
// Done with the back-end logic; now the HTML/JS portion
// -----------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1.0" />
  <title>Warehouse Optimization</title>
  <link rel="stylesheet" href="portal.css" />
  <link rel="icon" href="pictures/favicon.png" type="image/x-icon" />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
  />
  <style>
    h1, h2 {
      margin-bottom: 10px;
    }

    /* Put summary table and Grand Total box on one row, spaced and aligned */
    .summary-container {
      display: flex;
      flex-wrap: wrap-reverse;
      gap: 100px;
      align-items: flex-end;
      justify-content: space-between;
      margin-bottom: 20px;
      max-width: 1200px;
    }

    /* Summary Table Container */
    .summary-table-container {
      flex: 1;
      min-width: 300px;
      width: 100%;
    }

    /* Summary Table Styling */
    #summaryTable {
      border-collapse: collapse;
      width: 100%;
      text-align: center;
    }
    #summaryTable th,
    #summaryTable td {
      border: 1px solid #ccc;
      padding: 8px;
      text-align: center;
    }

    .grand-total-box {
      display: flex;
      flex-direction: column;
      justify-content: center;
      width: 100%;
      max-width: 220px;
      min-height: 100px;
      background-color: #f8f9fa;
      border: 1px solid #e5e5e5;
      border-radius: 6px;
      box-shadow: 0 0 10px rgba(0,0,0,0.05);
      padding: 15px;
      text-align: center;
      margin: 0 auto;
    }
    .grand-total-box h3 {
      margin: 0 0 5px 0;
      font-weight: 600;
    }
    .grand-total-box p {
      margin: 0;
      font-size: 1.2em;
      font-weight: 600;
    }

    /* Delivery Timeline Table */
    #deliveryTimelineTable {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      max-width: 650px;
    }
    #deliveryTimelineTable th,
    #deliveryTimelineTable td {
      padding: 8px;
      text-align: left;
      vertical-align: middle;
    }
    #deliveryTimelineTable th {
      width: 200px;
    }

    /* Wrap Warehouse Details table in a scrollable container */
    #warehouseContainer {
      overflow-x: auto;
      margin-bottom: 20px;
    }

    /* Warehouse Details Table */
    #warehouseTable {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      text-align: center;
    }
    #warehouseTable th,
    #warehouseTable td {
      border: 1px solid #ccc;
      padding: 8px;
    }
    #warehouseTable input {
      text-align: center;
    }

    /* Delivery Schedule Table */
    #scheduleContainer {
      overflow-x: auto;
      margin-bottom: 20px;
    }
    #scheduleTable {
      border-collapse: collapse;
      white-space: nowrap;
    }
    #scheduleTable th, #scheduleTable td {
      border: 1px solid #ccc;
      padding: 6px;
      text-align: center;
    }

    /* Sticky Warehouse + Metric Columns */
    .sticky-warehouse {
      position: sticky;
      left: 0;
      font-weight: bold;
      width: 150px;
      min-width: 150px;
      background-color: #fff;
      z-index: 2;
      border: 1px solid #ccc;
    }
    .sticky-metric {
      position: sticky;
      left: 150px;
      font-weight: bold;
      width: 100px;
      min-width: 100px;
      background-color: #fff;
      z-index: 1;
      border: 1px solid #ccc;
    }
    th.sticky-warehouse {
      background-color: #488C9A;
    }
    th.sticky-metric {
      background-color: #488C9A;
    }

    .warehouseHeaderFull {
      display: inline;
    }
    .warehouseHeaderShort {
      display: none;
    }

    .warehouseFull {
      display: inline;
    }
    .warehouseShort {
      display: none;
    }
    @media (max-width: 768px) {
      .warehouseHeaderFull {
        display: none;
      }
      .warehouseHeaderShort {
        display: inline;
      }
      .sticky-warehouse {
        min-width: 50px;
      }
      .sticky-metric {
        left: 45px;
      }
      .warehouseFull {
        display: none;
      }
      .warehouseShort {
        display: inline;
      }
    }

    /* Buttons: make them more pressable and portal-themed */
    button {
      padding: 8px 12px;
      margin: 5px;
      cursor: pointer;
      background-color: #488C9A;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      transition: background-color 0.3s, transform 0.3s;
    }
    button:hover {
      background-color: #66c0d0;
      transform: translateY(-2px);
    }
    button:active {
      background-color: #3b6f78;
      transform: translateY(0);
    }

    /* Modal for Pause Deliveries */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0, 0, 0, 0.4);
    }
    .modal-content {
      background-color: #fff;
      margin: 15% auto;
      padding: 20px;
      border: 1px solid #888;
      width: 300px;
      border-radius: 8px;
      position: relative;
    }
    .modal-content .close {
      position: absolute;
      right: 10px;
      top: 10px;
      cursor: pointer;
      font-size: 20px;
    }

    /* Thicker lines in schedule table groups */
    #scheduleTable tbody td {
      border-bottom: none;
      border-left: 1px solid #ccc;
      border-right: 1px solid #ccc;
    }
    #scheduleTable tbody tr:nth-child(3n+1) td {
      border-top: 2px solid #000;
    }
    #scheduleTable tbody tr:nth-child(3n) td {
      border-bottom: 2px solid #000;
    }
    #scheduleTable tbody tr td:first-child {
      border-left: 2px solid #000;
    }
    #scheduleTable tbody tr td:last-child {
      border-right: 2px solid #000;
    }

    /* Pause List styling */
    #pauseList {
      margin-top: 8px;
      font-weight: bold;
    }
    .pause-item {
      margin-bottom: 3px;
    }
    .pause-remove {
      color: red;
      margin-left: 10px;
      cursor: pointer;
      text-decoration: underline;
    }

    /* Enhanced styling for input fields */
    input:not([readonly]) {
      border: 2px solid #488C9A;
      border-radius: 4px;
      padding: 6px;
      background-color: #f0f8ff;
      transition: border-color 0.3s, box-shadow 0.3s;
    }
    input:not([readonly]):focus {
      border-color: #66c0d0;
      box-shadow: 0 0 8px rgba(72, 140, 154, 0.6);
      outline: none;
    }
    input[readonly] {
      border: none;
      background-color: transparent;
      color: #555;
    }

    /* Saved Scenarios List toggling */
    #savedScenariosContainer {
      margin: 20px 0;
      display: none;
    }
    #savedScenariosContainer table {
      border-collapse: collapse;
      width: 100%;
      max-width: 800px;
    }
    #savedScenariosContainer th, #savedScenariosContainer td {
      border: 1px solid #ccc;
      padding: 8px;
      text-align: center;
    }

    /* Save scenario modal */
    #saveModal {
      display: none;
      position: fixed;
      z-index: 1001;
      left: 0; top: 0;
      width: 100%; height: 100%;
      background-color: rgba(0, 0, 0, 0.4);
    }
    #saveModal .modal-content {
      width: 300px;
      margin: 10% auto;
      padding: 20px;
      background: #fff;
      border-radius: 8px;
      position: relative;
    }
    #saveModal .closeSaveModal {
      position: absolute;
      right: 10px; top: 10px;
      cursor: pointer;
      font-size: 20px;
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>
  <main>
    <h1>Warehouse Optimization</h1>

    <!-- Buttons for saved scenarios -->
    <div>
      <button type="button" onclick="toggleSavedScenarios()">Saved Scenarios</button>
      <button type="button" onclick="openSaveModal()">Save</button>
    </div>

    <!-- Hidden inputs to track scenario ID and load data on page -->
    <input type="hidden" id="currentScenarioId" value="<?php echo $loaded_scenario_id ?: ''; ?>">
    <input type="hidden" id="currentScenarioName" value="<?php echo htmlspecialchars($loaded_scenario_name ?? ''); ?>">

    <!-- Container of saved scenarios -->
    <div id="savedScenariosContainer">
      <h2>Saved Scenarios</h2>
      <?php if (!empty($saved_scenarios)): ?>
        <table>
          <tr>
            <th>Name</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
          <?php foreach ($saved_scenarios as $sc): ?>
            <tr>
              <td><?php echo htmlspecialchars($sc['name']); ?></td>
              <td><?php echo htmlspecialchars($sc['created_at']); ?></td>
              <td>
                <a href="warehouse_optimization.php?id=<?php echo $sc['id']; ?>">View</a>
                &nbsp;|&nbsp;
                <a href="warehouse_optimization.php?delete_id=<?php echo $sc['id']; ?>"
                   onclick="return confirm('Are you sure you want to delete this scenario?');">
                  Delete
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?>
        <p style="text-align:center;">No saved scenarios yet.</p>
      <?php endif; ?>
    </div>

    <!-- Summary + Grand Total side by side -->
    <h2>Summary</h2>
    <div class="summary-container">
      <div class="summary-table-container">
        <table id="summaryTable">
          <thead>
            <tr>
              <th>Warehouse # (Order)</th>
              <th>Total Cost ($)</th>
            </tr>
          </thead>
          <tbody id="summaryBody">
          </tbody>
        </table>
      </div>
      <div class="grand-total-box">
        <h3>Grand Total</h3>
        <p id="grandTotal">$0.00</p>
      </div>
    </div>

    <!-- Delivery Timeline Section -->
    <h2>Delivery Timeline</h2>
    <table id="deliveryTimelineTable">
      <tr>
        <th>Delivery Start</th>
        <td><input type="date" id="deliveryStart" onchange="updateSchedule()" /></td>
      </tr>
      <tr>
        <th>Delivery End</th>
        <td><input type="text" id="deliveryEnd" readonly /></td>
      </tr>
      <tr>
        <th>Trucks/Month</th>
        <td><input type="number" id="globalTrucksPerMonth" min="0" onchange="updateSchedule()" /></td>
      </tr>
      <tr>
        <th>Trucks Per Week</th>
        <td><input type="number" id="globalTrucksPerWeek" readonly /></td>
      </tr>
      <tr>
        <th>Pause Deliveries</th>
        <td>
          <button type="button" id="pauseBtn" onclick="openPauseModal()">Add Pause</button>
          <div id="pauseList"></div>
        </td>
      </tr>
    </table>

    <!-- Warehouse Details Table (Wrapped for responsiveness) -->
    <h2>Warehouse Details</h2>
    <button id="addWarehouseBtn" onclick="addWarehouse()">Add Warehouse</button>
    <button id="removeLastWarehouseBtn" onclick="removeLastWarehouse()">Remove Last Warehouse</button>

    <div id="warehouseContainer">
      <form id="warehouseForm">
        <table id="warehouseTable">
          <thead>
            <tr>
              <th>Order</th>
              <th># of Pallets</th>
              <th>Pallets per Truck</th>
              <th>Trucks Needed</th>
              <th>Cost/Pallet per Month ($)</th>
              <th>Warehouse Arrival</th>
            </tr>
          </thead>
          <tbody>
            <!-- Rows added dynamically -->
          </tbody>
        </table>
      </form>
    </div>

    <!-- Delivery Schedule Table -->
    <h2>Delivery Schedule</h2>
    <div id="scheduleContainer">
      <table id="scheduleTable"></table>
    </div>

    <!-- Modal for Pause Deliveries -->
    <div id="pauseModal" class="modal">
      <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2>Pause Deliveries</h2>
        <label for="pauseStart">Pause Start:</label>
        <input type="date" id="pauseStart" /><br /><br />
        <label for="pauseEnd">Pause End:</label>
        <input type="date" id="pauseEnd" /><br /><br />
        <button type="button" onclick="savePause()">Add Pause</button>
      </div>
    </div>

    <!-- Modal for Save scenario -->
    <div id="saveModal">
      <div class="modal-content">
        <span class="closeSaveModal" onclick="closeSaveModal()">&times;</span>
        <h2>Save Scenario</h2>
        <!-- We'll dynamically fill this container based on whether a scenario is loaded -->
        <div id="saveModalBody"></div>
      </div>
    </div>
  </main>

  <script>
    // ------------------ Utility Functions ------------------ //

    function parseDateInput(dateStr) {
      if (!dateStr) return null;
      const parts = dateStr.split("-");
      return new Date(
        parseInt(parts[0], 10),
        parseInt(parts[1], 10) - 1,
        parseInt(parts[2], 10)
      );
    }

    function formatNumber(num) {
      return Number(num).toLocaleString();
    }

    function formatCurrency(num) {
      return (
        "$" +
        parseFloat(num).toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        })
      );
    }

    function formatMMDDYYYY(date) {
      let mm = String(date.getMonth() + 1).padStart(2, "0");
      let dd = String(date.getDate()).padStart(2, "0");
      let yyyy = date.getFullYear();
      return mm + "/" + dd + "/" + yyyy;
    }

    function daysInMonth(date) {
      let y = date.getFullYear();
      let m = date.getMonth() + 1;
      return new Date(y, m, 0).getDate();
    }

    function addMonths(date, n) {
      let newDate = new Date(date.getTime());
      newDate.setMonth(newDate.getMonth() + n);
      while (newDate.getMonth() % 12 !== (date.getMonth() + n) % 12) {
        newDate.setDate(newDate.getDate() - 1);
      }
      return newDate;
    }

    function firstOfMonth(date) {
      let d = new Date(date);
      d.setDate(1);
      return d;
    }

    // Count how many days in [start, end) are not paused
    function countNonPausedDaysInRange(start, end, globalPauses) {
      let dayCursor = new Date(start);
      let count = 0;
      while (dayCursor < end) {
        if (!isPausedOnDay(dayCursor, globalPauses)) {
          count++;
        }
        dayCursor.setDate(dayCursor.getDate() + 1);
      }
      return count;
    }

    function getNonPausedDaysInMonth(monthStart, globalPauses) {
      let nextMonth = addMonths(monthStart, 1);
      return countNonPausedDaysInRange(monthStart, nextMonth, globalPauses);
    }

    function isPausedOnDay(day, globalPauses) {
      for (let p of globalPauses) {
        let start = parseDateInput(p.start);
        let end = parseDateInput(p.end);
        if (day >= start && day < end) {
          return true;
        }
      }
      return false;
    }

    // ------------------ Global State ------------------ //
    let warehouseCount = 0;
    const maxWarehouses = 9;
    let globalPauses = [];

    // ------------------ Warehouse Management ------------------ //

    function addWarehouse() {
      if (warehouseCount >= maxWarehouses) {
        alert("Maximum of " + maxWarehouses + " warehouses reached.");
        return;
      }
      warehouseCount++;
      const tbody = document.getElementById("warehouseTable").querySelector("tbody");

      const tr = document.createElement("tr");
      tr.id = "warehouseRow" + warehouseCount;
      tr.innerHTML =
        `<td><input type="number" value="${warehouseCount}" id="order_${warehouseCount}" onchange="updateRow(${warehouseCount})"></td>
         <td><input type="number" min="0" id="pallets_${warehouseCount}" onchange="updateRow(${warehouseCount})"></td>
         <td><input type="number" min="1" id="palletsPerTruck_${warehouseCount}" onchange="updateRow(${warehouseCount})"></td>
         <td><input type="number" id="trucksNeeded_${warehouseCount}" readonly></td>
         <td><input type="number" min="0" step="0.01" id="costPerPallet_${warehouseCount}" onchange="updateRow(${warehouseCount})"></td>
         <td><input type="date" id="arrival_${warehouseCount}" onchange="updateSchedule()"></td>`;

      tbody.appendChild(tr);

      updateRow(warehouseCount);
      updateSchedule();
    }

    function removeLastWarehouse() {
      const tbody = document.getElementById("warehouseTable").querySelector("tbody");
      const rows = tbody.querySelectorAll("tr");
      if (rows.length > 0) {
        rows[rows.length - 1].remove();
        renumberWarehouses();
      }
    }

    function renumberWarehouses() {
      const rows = document.querySelectorAll("#warehouseTable tbody tr");
      let newIndex = 1;
      rows.forEach((row) => {
        row.id = "warehouseRow" + newIndex;
        row.querySelector('[id^="order_"]').id = "order_" + newIndex;
        row.querySelector('[id^="pallets_"]').id = "pallets_" + newIndex;
        row.querySelector('[id^="palletsPerTruck_"]').id = "palletsPerTruck_" + newIndex;
        row.querySelector('[id^="trucksNeeded_"]').id = "trucksNeeded_" + newIndex;
        row.querySelector('[id^="costPerPallet_"]').id = "costPerPallet_" + newIndex;
        row.querySelector('[id^="arrival_"]').id = "arrival_" + newIndex;
        newIndex++;
      });
      warehouseCount = newIndex - 1;
      updateSchedule();
    }

    function updateRow(rowNum) {
      const pallets = parseFloat(document.getElementById("pallets_" + rowNum).value) || 0;
      const ppt = parseFloat(document.getElementById("palletsPerTruck_" + rowNum).value) || 1;
      const trucksNeeded = Math.ceil(pallets / ppt);
      document.getElementById("trucksNeeded_" + rowNum).value = trucksNeeded;
      updateSchedule();
    }

    // ------------------ Pause Logic ------------------ //

    function openPauseModal() {
      document.getElementById("pauseStart").value = "";
      document.getElementById("pauseEnd").value = "";
      document.getElementById("pauseModal").style.display = "block";
    }

    function closeModal() {
      document.getElementById("pauseModal").style.display = "none";
    }

    function savePause() {
      const ps = document.getElementById("pauseStart").value;
      const pe = document.getElementById("pauseEnd").value;
      if (!ps || !pe) {
        alert("Please enter both start and end dates for the pause.");
        return;
      }
      let startDate = parseDateInput(ps);
      let endDate = parseDateInput(pe);
      if (endDate <= startDate) {
        alert("Pause End must be after Pause Start.");
        return;
      }
      globalPauses.push({ start: ps, end: pe });
      renderPauses();
      closeModal();
      updateSchedule();
    }

    function renderPauses() {
      const pauseList = document.getElementById("pauseList");
      pauseList.innerHTML = "";
      if (globalPauses.length === 0) return;
      globalPauses.forEach((p, idx) => {
        const div = document.createElement("div");
        div.className = "pause-item";
        div.innerHTML =
          `${p.start} - ${p.end}
           <span class="pause-remove" onclick="removePause(${idx})">[Remove]</span>`;
        pauseList.appendChild(div);
      });
    }

    function removePause(index) {
      globalPauses.splice(index, 1);
      renderPauses();
      updateSchedule();
    }

    // ------------------ Main Shipping + Scheduling ------------------ //

    function updateSchedule() {
      const globalTrucksPerMonth = parseFloat(document.getElementById("globalTrucksPerMonth").value) || 0;
      const globalStartStr = document.getElementById("deliveryStart").value;
      const globalStart = globalStartStr ? parseDateInput(globalStartStr) : new Date();

      // Update the "Trucks/Week" display
      document.getElementById("globalTrucksPerWeek").value =
        globalTrucksPerMonth > 0 ? Math.round(globalTrucksPerMonth / 4) : 0;

      // Gather all warehouses
      let wData = [];
      for (let i = 1; i <= warehouseCount; i++) {
        const row = document.getElementById("warehouseRow" + i);
        if (!row) continue;

        let orderVal = parseFloat(document.getElementById("order_" + i).value) || i;
        let p = parseFloat(document.getElementById("pallets_" + i).value) || 0;
        let ppt = parseFloat(document.getElementById("palletsPerTruck_" + i).value) || 1;
        let trucksNeeded = Math.ceil(p / ppt);
        let costPerPallet = parseFloat(document.getElementById("costPerPallet_" + i).value) || 0;
        let arrivalStr = document.getElementById("arrival_" + i).value;

        wData.push({
          warehouseId: i,
          orderVal: orderVal,
          pallets: p,
          palletsPerTruck: ppt,
          trucksNeeded: trucksNeeded,
          costPerPallet: costPerPallet,
          arrival: arrivalStr
        });
      }

      // If no warehouses, show a minimal schedule and zero summary
      if (wData.length === 0) {
        const now = new Date();
        let scheduleHTML = "<thead><tr>";
        scheduleHTML += `<th class="sticky-warehouse">
                            <span class="warehouseHeaderFull">Warehouse</span>
                            <span class="warehouseHeaderShort">WH</span>
                         </th>`;
        scheduleHTML += `<th class="sticky-metric">Metric</th>`;
        scheduleHTML += `<th>${now.toLocaleString("default",{month:"short"})} ${now.getFullYear()}</th>`;
        scheduleHTML += "</tr></thead><tbody>";
        scheduleHTML += "<tr>";
        scheduleHTML +=
          `<td class="sticky-warehouse">
             <span class="warehouseFull">Warehouse</span>
             <span class="warehouseShort">WH</span>
           </td>`;
        scheduleHTML += `<td class="sticky-metric">--</td>`;
        scheduleHTML += `<td>--</td>`;
        scheduleHTML += "</tr></tbody>";
        document.getElementById("scheduleTable").innerHTML = scheduleHTML;
        document.getElementById("deliveryEnd").value = formatMMDDYYYY(now);
        updateSummaryWithSchedule([]);
        return;
      }

      // Convert each arrival to a Date for scheduling logic
      let wDataForSchedule = wData.map( wd => {
        return {
          ...wd,
          arrivalDate: wd.arrival ? parseDateInput(wd.arrival) : null,
          done: false
        };
      });

      // Sort by "orderVal"
      wDataForSchedule.sort((a,b) => a.orderVal - b.orderVal);

      // scheduleData[warehouseId][month_start_ms] = {pallets, truckloads, monthlyCost, dateObj}
      let scheduleData = {};
      for (let w of wDataForSchedule) {
        scheduleData[w.warehouseId] = {};
      }

      // find earliest date
      let earliestDate = new Date(globalStart);
      for (let w of wDataForSchedule) {
        if (w.arrivalDate && w.arrivalDate < earliestDate) {
          earliestDate = w.arrivalDate;
        }
      }

      let currentMonth = firstOfMonth(earliestDate);
      let allDone = false;
      let loopCount = 0;
      const MAX_LOOP = 360; // 30 years
      let finishDates = {};

      while (!allDone && loopCount < MAX_LOOP) {
        let monthStart = new Date(currentMonth);
        let nextMonth = addMonths(monthStart, 1);
        let dim = daysInMonth(monthStart);

        let totalActiveDays = getNonPausedDaysInMonth(monthStart, globalPauses);
        let monthlyCapacity = 0;

        if (totalActiveDays > 0) {
          if (nextMonth <= globalStart) {
            monthlyCapacity = 0;
          }
          else if (monthStart >= globalStart) {
            let fraction = totalActiveDays / dim;
            monthlyCapacity = Math.round(globalTrucksPerMonth * fraction);
          }
          else {
            // partial overlap
            let shippingDaysAfterStart = countNonPausedDaysInRange(globalStart, nextMonth, globalPauses);
            let fraction = shippingDaysAfterStart / totalActiveDays;
            monthlyCapacity = Math.round(globalTrucksPerMonth * fraction);
          }
        }

        let capacityLeft = monthlyCapacity;
        let usedTrucksSoFar = 0;

        for (let w of wDataForSchedule) {
          if (w.done) continue;
          if (w.arrivalDate && monthStart < w.arrivalDate) {
            // not arrived yet
            continue;
          }

          let palletsStart = w.pallets;
          let monthlyCost = palletsStart * w.costPerPallet;
          scheduleData[w.warehouseId][monthStart.getTime()] = {
            pallets: palletsStart,
            truckloads: w.trucksNeeded,
            monthlyCost: monthlyCost,
            dateObj: new Date(monthStart)
          };

          if (capacityLeft > 0 && w.trucksNeeded > 0 && w.pallets > 0) {
            let shipped = Math.min(capacityLeft, w.trucksNeeded);
            capacityLeft -= shipped;
            w.trucksNeeded -= shipped;

            let palletsShipped = shipped * w.palletsPerTruck;
            if (palletsShipped > w.pallets) {
              palletsShipped = w.pallets;
            }
            w.pallets -= palletsShipped;
            usedTrucksSoFar += shipped;

            if (w.trucksNeeded <= 0 || w.pallets <= 0) {
              w.done = true;
              let finishingFraction = (monthlyCapacity > 0)
                ? (usedTrucksSoFar / monthlyCapacity)
                : 1;
              if (finishingFraction > 1) finishingFraction = 1;
              let dayUsed = Math.ceil(dim * finishingFraction);
              if (dayUsed < 1) dayUsed=1;
              if (dayUsed>dim) dayUsed=dim;
              let fDate = new Date(monthStart);
              fDate.setDate(dayUsed);
              finishDates[w.warehouseId] = fDate;
            }
          }
        }

        allDone = wDataForSchedule.every(ww => ww.done === true);
        currentMonth = nextMonth;
        loopCount++;
      }

      // if any not done, push them out far
      let nowPlus10Years = addMonths(new Date(), 120);
      for (let w of wDataForSchedule) {
        if (!w.done) {
          finishDates[w.warehouseId] = nowPlus10Years;
        }
      }

      // gather all monthStart times
      let allMonthTimes = new Set();
      for (let wid in scheduleData) {
        for (let mt in scheduleData[wid]) {
          allMonthTimes.add(parseInt(mt));
        }
      }
      if (allMonthTimes.size === 0) {
        // minimal schedule
        const now = new Date();
        let scheduleHTML = "<thead><tr>";
        scheduleHTML += `<th class="sticky-warehouse">
                            <span class="warehouseHeaderFull">Warehouse</span>
                            <span class="warehouseHeaderShort">WH</span>
                         </th>`;
        scheduleHTML += `<th class="sticky-metric">Metric</th>`;
        scheduleHTML += `<th>${now.toLocaleString("default",{month:"short"})} ${now.getFullYear()}</th>`;
        scheduleHTML += "</tr></thead><tbody>";
        scheduleHTML += "<tr>";
        scheduleHTML +=
          `<td class="sticky-warehouse">
             <span class="warehouseFull">Warehouse</span>
             <span class="warehouseShort">WH</span>
           </td>`;
        scheduleHTML += `<td class="sticky-metric">--</td>`;
        scheduleHTML += `<td>--</td>`;
        scheduleHTML += "</tr></tbody>";
        document.getElementById("scheduleTable").innerHTML = scheduleHTML;
        document.getElementById("deliveryEnd").value = formatMMDDYYYY(now);
        updateSummaryWithSchedule([]);
        return;
      }

      let monthTimesArr = Array.from(allMonthTimes).sort((a,b) => a - b);

      let scheduleHTML = "<thead><tr>";
      scheduleHTML += `<th rowspan='2' class='sticky-warehouse'>
                          <span class="warehouseHeaderFull">Warehouse</span>
                          <span class="warehouseHeaderShort">WH</span>
                       </th>`;
      scheduleHTML += `<th rowspan='2' class='sticky-metric'>Metric</th>`;
      monthTimesArr.forEach((mt) => {
        let d = new Date(mt);
        scheduleHTML += `<th>${d.toLocaleString("default",{ month:"short" })} ${d.getFullYear()}</th>`;
      });
      scheduleHTML += "</tr></thead><tbody>";

      // Re-sort wDataForSchedule by final orderVal
      wDataForSchedule.sort((a,b) => a.orderVal - b.orderVal);

      for (let w of wDataForSchedule) {
        let wId = w.warehouseId;
        // Pallets row
        scheduleHTML += "<tr>";
        scheduleHTML += `<td class="sticky-warehouse" rowspan="3" style="border:2px solid #000;">
                           <span class="warehouseFull">Warehouse ${wId}</span>
                           <span class="warehouseShort">WH ${wId}</span>
                         </td>`;
        scheduleHTML += `<td class="sticky-metric">Pallets</td>`;
        monthTimesArr.forEach(mt => {
          let rec = scheduleData[wId][mt];
          scheduleHTML += `<td>${rec ? formatNumber(rec.pallets) : "0"}</td>`;
        });
        scheduleHTML += "</tr>";

        // Truckloads row
        scheduleHTML += "<tr>";
        scheduleHTML += `<td class="sticky-metric">Truckloads</td>`;
        monthTimesArr.forEach(mt => {
          let rec = scheduleData[wId][mt];
          scheduleHTML += `<td>${rec ? formatNumber(rec.truckloads) : "0"}</td>`;
        });
        scheduleHTML += "</tr>";

        // Monthly Cost row
        scheduleHTML += "<tr>";
        scheduleHTML += `<td class="sticky-metric">Monthly Cost ($)</td>`;
        monthTimesArr.forEach(mt => {
          let rec = scheduleData[wId][mt];
          scheduleHTML += `<td>${rec ? formatCurrency(rec.monthlyCost) : "$0.00"}</td>`;
        });
        scheduleHTML += "</tr>";
      }

      scheduleHTML += "</tbody>";
      document.getElementById("scheduleTable").innerHTML = scheduleHTML;

      // finalEnd
      let finalEnd = new Date(globalStart);
      for (let w of wDataForSchedule) {
        let f = finishDates[w.warehouseId];
        if (f && f > finalEnd) {
          finalEnd = f;
        }
      }
      document.getElementById("deliveryEnd").value = formatMMDDYYYY(finalEnd);

      // Build summary array
      let schedules = [];
      for (let w of wDataForSchedule) {
        let whArr = [];
        let totalCost = 0;
        if (scheduleData[w.warehouseId]) {
          for (let mt in scheduleData[w.warehouseId]) {
            let rec = scheduleData[w.warehouseId][mt];
            totalCost += rec.monthlyCost;
            whArr.push(rec);
          }
        }
        schedules.push({
          warehouseId: w.warehouseId,
          orderVal: w.orderVal,
          data: whArr,
          finish: finishDates[w.warehouseId],
        });
      }
      updateSummaryWithSchedule(schedules);
    }

    function updateSummaryWithSchedule(schedules) {
      let results = [];
      let grandTotal = 0;
      schedules.forEach((sch) => {
        let wTotal = 0;
        sch.data.forEach((md) => {
          wTotal += md.monthlyCost;
        });
        results.push({
          warehouseId: sch.warehouseId,
          orderVal: sch.orderVal,
          totalCost: wTotal
        });
      });

      results.sort((a, b) => a.orderVal - b.orderVal);

      let summaryBody = document.getElementById("summaryBody");
      summaryBody.innerHTML = "";
      results.forEach((r) => {
        grandTotal += r.totalCost;
        let tr = document.createElement("tr");
        tr.innerHTML =
          `<td>Warehouse ${r.warehouseId} (Order: ${r.orderVal})</td>
           <td>${formatCurrency(r.totalCost)}</td>`;
        summaryBody.appendChild(tr);
      });
      document.getElementById("grandTotal").innerText = formatCurrency(grandTotal);
    }

    // -------------- Show/Hide Saved Scenarios ------------
    function toggleSavedScenarios() {
      const container = document.getElementById('savedScenariosContainer');
      if (container.style.display === 'none' || container.style.display === '') {
        container.style.display = 'block';
      } else {
        container.style.display = 'none';
      }
    }

    // ----------------- SAVE SCENARIO MODAL ---------------
    function openSaveModal() {
      const scenarioId = document.getElementById('currentScenarioId').value;
      const scenarioName = document.getElementById('currentScenarioName').value.trim();
      const modalBody = document.getElementById('saveModalBody');
      modalBody.innerHTML = '';

      if (!scenarioId) {
        // No existing scenario -> "Save as new" flow
        modalBody.innerHTML = `
          <label for="scenarioName">Scenario Name:</label>
          <input type="text" id="scenarioNameInput" style="width:100%;" value="${scenarioName}" />
          <br><br>
          <button type="button" onclick="saveAsNew()">Save</button>
        `;
      } else {
        // Existing scenario -> Overwrite warning
        modalBody.innerHTML = `
          <p>You are about to overwrite an existing file named "${scenarioName}". Do you want to proceed?</p>
          <label for="scenarioNameInput">Scenario Name:</label>
          <input type="text" id="scenarioNameInput" style="width:100%;" value="${scenarioName}" />
          <br><br>
          <button type="button" onclick="saveOverwrite()">Yes</button>
          <button type="button" onclick="closeSaveModal()">No</button>
        `;
      }

      document.getElementById('saveModal').style.display = 'block';
    }

    function closeSaveModal() {
      document.getElementById('saveModal').style.display = 'none';
    }

    // Gather all fields into a JSON
    function gatherScenarioData() {
      const data = {
        scenario_type: 'warehouse_optimization', 
        deliveryStart: document.getElementById('deliveryStart').value,
        globalTrucksPerMonth: parseFloat(document.getElementById('globalTrucksPerMonth').value) || 0,
        pauses: [...globalPauses],

        warehouses: [],
      };

      for (let i=1; i <= warehouseCount; i++) {
        const row = document.getElementById("warehouseRow" + i);
        if (!row) continue;
        data.warehouses.push({
          order: parseFloat(document.getElementById("order_" + i).value) || i,
          pallets: parseFloat(document.getElementById("pallets_" + i).value) || 0,
          palletsPerTruck: parseFloat(document.getElementById("palletsPerTruck_" + i).value) || 1,
          costPerPallet: parseFloat(document.getElementById("costPerPallet_" + i).value) || 0,
          arrival: document.getElementById("arrival_" + i).value || ''
        });
      }

      return data;
    }

    function saveAsNew() {
      const scenarioName = document.getElementById('scenarioNameInput').value.trim();
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';

      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'save_scenario';
      form.appendChild(actionInput);

      const jsonInput = document.createElement('input');
      jsonInput.type = 'hidden';
      jsonInput.name = 'scenario_json';
      jsonInput.value = JSON.stringify(gatherScenarioData());
      form.appendChild(jsonInput);

      const nameInput = document.createElement('input');
      nameInput.type = 'hidden';
      nameInput.name = 'scenario_name';
      nameInput.value = scenarioName;
      form.appendChild(nameInput);

      // No scenario_id => means insert new
      const scenarioIdInput = document.createElement('input');
      scenarioIdInput.type = 'hidden';
      scenarioIdInput.name = 'scenario_id';
      scenarioIdInput.value = '';
      form.appendChild(scenarioIdInput);

      document.body.appendChild(form);
      form.submit();
    }

    function saveOverwrite() {
      const currentId = document.getElementById('currentScenarioId').value;
      if (!currentId) {
        alert("No existing scenario is loaded, so 'Overwrite Existing' won't work. Please use 'Save' to create a new scenario.");
        return;
      }

      const scenarioName = document.getElementById('scenarioNameInput').value.trim();
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';

      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'save_scenario';
      form.appendChild(actionInput);

      const jsonInput = document.createElement('input');
      jsonInput.type = 'hidden';
      jsonInput.name = 'scenario_json';
      jsonInput.value = JSON.stringify(gatherScenarioData());
      form.appendChild(jsonInput);

      const nameInput = document.createElement('input');
      nameInput.type = 'hidden';
      nameInput.name = 'scenario_name';
      nameInput.value = scenarioName;
      form.appendChild(nameInput);

      const scenarioIdInput = document.createElement('input');
      scenarioIdInput.type = 'hidden';
      scenarioIdInput.name = 'scenario_id';
      scenarioIdInput.value = currentId;
      form.appendChild(scenarioIdInput);

      document.body.appendChild(form);
      form.submit();
    }

    // ----------------- LOAD SCENARIO (if any) -------------
    function loadScenarioFromPHP() {
      const loadedData = <?php
        echo $loaded_scenario_data ? json_encode($loaded_scenario_data) : 'null';
      ?>;
      if (!loadedData) {
        // if none, just create 1 warehouse row by default
        if (warehouseCount === 0) addWarehouse();
        return;
      }

      if (loadedData.deliveryStart) {
        document.getElementById('deliveryStart').value = loadedData.deliveryStart;
      }
      if (loadedData.globalTrucksPerMonth) {
        document.getElementById('globalTrucksPerMonth').value = loadedData.globalTrucksPerMonth;
      }
      if (Array.isArray(loadedData.pauses)) {
        globalPauses = loadedData.pauses;
      }
      renderPauses();

      if (Array.isArray(loadedData.warehouses)) {
        const tbody = document.querySelector("#warehouseTable tbody");
        tbody.innerHTML = '';
        warehouseCount = 0;

        loadedData.warehouses.forEach((wh) => {
          addWarehouse();
          const rowNum = warehouseCount;
          document.getElementById("order_" + rowNum).value = wh.order ?? rowNum;
          document.getElementById("pallets_" + rowNum).value = wh.pallets ?? 0;
          document.getElementById("palletsPerTruck_" + rowNum).value = wh.palletsPerTruck ?? 1;
          document.getElementById("costPerPallet_" + rowNum).value = wh.costPerPallet ?? 0;
          if (wh.arrival) {
            document.getElementById("arrival_" + rowNum).value = wh.arrival;
          }
          updateRow(rowNum);
        });
      }

      updateSchedule();
    }

    // Close modal if user clicks outside it
    window.onclick = function(event) {
      const pauseModal = document.getElementById("pauseModal");
      if (event.target === pauseModal) {
        closeModal();
      }
      const sModal = document.getElementById("saveModal");
      if (event.target === sModal) {
        closeSaveModal();
      }
    };

    // On load
    window.onload = function() {
      loadScenarioFromPHP();
    };
  </script>
</body>
</html>
