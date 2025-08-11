<?php
session_name("logistics_session");
session_start();

// ---------- AUTH & INPUT --------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$user_id  = $_SESSION['user_id'];
$role     = $_SESSION['role'] ?? 'user';

$project_id           = isset($_GET['project_id'])    ? intval($_GET['project_id'])    : null;
$origin_batch_id      = isset($_GET['origin_batch_id']) ? intval($_GET['origin_batch_id']) : null;
$highlight_delivery_id= isset($_GET['delivery_id'])   ? intval($_GET['delivery_id'])   : null;
if (!$project_id && !$origin_batch_id) die("Project ID or Origin Batch ID is missing.");

// ---------- DB ------------------------------------------------------------
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

$page_title_info  = "Delivery Tracker";
$breadcrumbs      = [];
$source_vendor_name_for_batch = null;

/* -------------------- context by project_id -----------------------------*/
if ($project_id) {
    if ($role === 'admin' || $role === 'global_admin') {
        $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
    } else {
        $stmt = $conn->prepare("
            SELECT p.* FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $project_id, $user_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) die("You do not have access to this project.");
    $project = $res->fetch_assoc();
    $stmt->close();

    $page_title_info = htmlspecialchars($project['project_name']);
    $breadcrumbs[]   = ['href'=>'dashboard.php',                               'text'=>'Dashboard'];
    $breadcrumbs[]   = ['href'=>"project_overview.php?project_id=$project_id", 'text'=>'Project Overview'];
    $breadcrumbs[]   = ['text'=>'Delivery Tracker'];

/* -------------------- context by origin_batch_id ------------------------*/
} elseif ($origin_batch_id) {
    $stmt = $conn->prepare("SELECT vendor_name, account_id FROM modules WHERE id = ?");
    $stmt->bind_param("i", $origin_batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$batch) die("Batch not found.");

    $source_vendor_name_for_batch = $batch['vendor_name'];
    $batch_account_id             = $batch['account_id'];

    if ($role === 'user') {
        $stmt = $conn->prepare("SELECT 1 FROM customer_account_users WHERE user_id=? AND account_id=? LIMIT 1");
        $stmt->bind_param("ii", $user_id, $batch_account_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) die("Forbidden.");
        $stmt->close();
    }

    $page_title_info = "Unassigned Deliveries from Batch: "
                     . htmlspecialchars($source_vendor_name_for_batch)
                     . " (ID: $origin_batch_id)";
    $breadcrumbs[]   = ['href'=>'modules.php',                              'text'=>'Modules'];
    $breadcrumbs[]   = ['href'=>"module_overview.php?batch_id=$origin_batch_id", 'text'=>'Batch Details'];
    $breadcrumbs[]   = ['text'=>'Unassigned Deliveries from Batch'];
}

/* ---------- FILTER LOGIC -------------------------------------------------*/
$filterColumn = "COALESCE(actual_delivery_date, anticipated_delivery_date)";
$time_filter  = $_GET['time_filter'] ?? 'all';
$ref_date     = $_GET['ref_date']     ?? date('Y-m-d');

$baseWhere = [];  $paramTypes = '';  $params = [];

/* context filters */
$selectClause = "SELECT d.*, ss.id as appointment_id FROM deliveries d";
$joinClause   = " LEFT JOIN site_scheduling ss ON d.id = ss.delivery_id";
if ($project_id) {
    $baseWhere[] = "d.project_id = ?";
    $paramTypes .= "i";
    $params[]    = $project_id;
} else {
    $joinClause  .= " LEFT JOIN projects p ON d.project_id = p.id";
    $baseWhere[]  = "d.supplier = ?";
    $paramTypes  .= "s";
    $params[]     = $source_vendor_name_for_batch;
    $baseWhere[]  = "d.project_id IS NULL";
}

/* time filters */
$dateLabel="All Deliveries"; $prev_date=""; $next_date="";
if ($time_filter === 'day') {
    $baseWhere[] = "DATE($filterColumn)=?";
    $paramTypes .="s"; $params[]=$ref_date;
    $dateLabel = date('F j, Y',strtotime($ref_date));
    $prev_date = date('Y-m-d',strtotime("$ref_date -1 day"));
    $next_date = date('Y-m-d',strtotime("$ref_date +1 day"));
} elseif ($time_filter === 'week') {
    $ts=strtotime($ref_date); $start=date('Y-m-d',strtotime("-".date('w',$ts)." days",$ts));
    $end=date('Y-m-d',strtotime("$start +6 days"));
    $baseWhere[]="DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes.="ss"; array_push($params,$start,$end);
    $dateLabel = date('M j',strtotime($start))." - ".date('M j, Y',strtotime($end));
    $prev_date = date('Y-m-d',strtotime("$start -7 days"));
    $next_date = date('Y-m-d',strtotime("$start +7 days"));
} elseif ($time_filter === 'month') {
    $start=date('Y-m-01',strtotime($ref_date));
    $end  =date('Y-m-t', strtotime($ref_date));
    $baseWhere[]="DATE($filterColumn) BETWEEN ? AND ?";
    $paramTypes.="ss"; array_push($params,$start,$end);
    $dateLabel = date('F Y',strtotime($ref_date));
    $prev_date = date('Y-m-d',strtotime("$start -1 month"));
    $next_date = date('Y-m-d',strtotime("$start +1 month"));
}

/* status filter */
$status_filter=$_GET['status_filter']??'';
if($status_filter!==''){
    $baseWhere[]="status_of_delivery = ?";
    $paramTypes.="s"; $params[]=$status_filter;
}
$whereClause="WHERE 1=1".($baseWhere?" AND ".implode(" AND ",$baseWhere):"");

/* ---------- CSV EXPORT ---------------------------------------------------*/
if(isset($_GET['export']) && $_GET['export']==1){
    header('Content-Type:text/csv; charset=utf-8');
    header('Content-Disposition:attachment; filename=deliveries.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Supplier','Wattage','Status of Delivery','Quantity','BOL Number',
                   'Anticipated Delivery Date','Actual Delivery Date',
                   'Associated Pallets','Scheduled','Proof of Delivery']);
    $sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
    $stmt=$conn->prepare($sql);
    if($paramTypes) $stmt->bind_param($paramTypes,...$params);
    $stmt->execute();
    $r=$stmt->get_result();
    while($row=$r->fetch_assoc()){
        fputcsv($out,[
            $row['supplier'],$row['wattage'],$row['status_of_delivery'],$row['quantity'],
            $row['bol_number'],
            $row['anticipated_delivery_date']?date('m-d-Y',strtotime($row['anticipated_delivery_date'])):'',
            $row['actual_delivery_date']?date('m-d-Y',strtotime($row['actual_delivery_date'])):'',
            $row['associated_pallets'],
            $row['scheduled'] ? 'Yes' : 'No',
            $row['proof_of_delivery']?'Yes':'No'
        ]);
    }
    fclose($out); $stmt->close(); $conn->close(); exit();
}

/* ---------- FETCH DELIVERIES --------------------------------------------*/
$sql="$selectClause $joinClause $whereClause ORDER BY $filterColumn DESC";
$stmt=$conn->prepare($sql);
if($paramTypes) $stmt->bind_param($paramTypes,...$params);
$stmt->execute();
$deliveries=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title_info; ?> - Delivery Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ======  original inline CSS (unchanged) ====== */
        .container{margin:0px;} /* … (omitted for brevity) … */
        .column-hidden{display:none !important;}
        .time-filter-header{display:flex;justify-content:space-between;align-items:center;margin-top:30px;margin-bottom:10px;flex-wrap:wrap;}
        .time-filters{display:flex;gap:10px;}
        .time-filters a{text-decoration:none;padding:6px 12px;background:#eee;border-radius:4px;color:#333;}
        .time-filters a.active{background:#488C9A;color:#fff;}
        .date-navigation{display:flex;align-items:center;gap:10px;margin:20px;}
        .nav-arrow{font-weight:bold;cursor:pointer;background:#eee;border:none;padding:5px 10px;border-radius:4px;}
        .nav-arrow:hover{background:#ccc;}
        .date-label{font-weight:bold;font-size:1.1em;}
        .right-filters{display:flex;flex-direction:column;gap:10px;align-items:flex-start;}
        .back-icon{display:inline-flex;align-items:center;text-decoration:none;margin:10px;color:#333;}
        .back-icon svg{width:24px;height:24px;margin-right:5px;}
        .breadcrumb{display:flex;margin-bottom:20px;margin-top:10px;margin-left:20px;}
        .breadcrumb a{color:#488C9A;text-decoration:none;}
        .breadcrumb .separator{margin:0 8px;color:#6c757d;}
        table{width:100%;border-collapse:collapse;margin-bottom:20px;}
        table,th,td{border:1px solid #ccc;}
        th,td{padding:10px;}
        tr:hover{background:#f1f1f1;}
        .table-responsive{width:100%;overflow-x:auto;}
        @media screen and (max-width:768px){.mobile-hide{display:none !important;}}
        .highlighted-delivery{background:linear-gradient(135deg,#fff3cd 0%,#ffeaa7 100%) !important;border:3px solid #488C9A !important;box-shadow:0 0 15px rgba(72,140,154,0.3) !important;animation:highlightPulse 2s ease-in-out;}
        @keyframes highlightPulse{0%,100%{transform:scale(1);box-shadow:0 0 15px rgba(72,140,154,0.3);}50%{transform:scale(1.02);box-shadow:0 0 25px rgba(72,140,154,0.5);}}
        /* Filters dropdown */
        .filters-dropdown-container{position:relative;display:inline-block;}
        .filters-dropdown-btn{background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border:none;padding:12px 20px;border-radius:10px;cursor:pointer;font-size:.95em;font-weight:600;transition:all .3s cubic-bezier(.25,.46,.45,.94);display:flex;align-items:center;gap:8px;box-shadow:0 4px 12px rgba(72,140,154,.3);}
        .filters-dropdown-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(72,140,154,.4);}
        .filters-dropdown-content{position:absolute;top:100%;right:0;background:white;border:1px solid #e1e5e9;border-radius:12px;box-shadow:0 12px 24px rgba(0,0,0,.15);z-index:1000;min-width:400px;max-width:500px;max-height:500px;overflow-y:auto;backdrop-filter:blur(10px);}
        .filters-dropdown-header{padding:16px 20px;background:linear-gradient(135deg,#488C9A 0%,#3a6e7f 100%);color:white;border-bottom:none;font-weight:700;font-size:1.1em;text-align:center;border-radius:12px 12px 0 0;}
        .filter-item{padding:16px 20px;border-bottom:1px solid #f1f3f4;}
        .filter-item:last-child{border-bottom:none;border-radius:0 0 12px 12px;}
        .filter-item label{display:block;font-weight:600;color:#293E4C;margin-bottom:8px;font-size:.95em;}
        .filter-item input,.filter-item select{width:95%;padding:10px 12px;border:2px solid #e1e5e9;border-radius:8px;font-size:14px;transition:border-color .2s ease,box-shadow .2s ease;background-color:white;}
        .filter-item input:focus,.filter-item select:focus{outline:none;border-color:#488C9A;box-shadow:0 0 0 3px rgba(72,140,154,.1);}
        /* Column chooser */
        .column-chooser-container{position:relative;display:inline-block;}
        .column-chooser-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .column-chooser-btn:hover{background-color:#3A6E7F;}
        .calendar-view-btn{background-color:#488C9A;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:.9em;transition:background-color .3s ease;}
        .calendar-view-btn:hover{background-color:#3A6E7F;}
        .column-chooser-dropdown{position:absolute;top:100%;right:0;background:white;border:1px solid #ddd;border-radius:4px;box-shadow:0 4px 8px rgba(0,0,0,.1);z-index:1000;min-width:250px;max-width:300px;max-height:400px;overflow-y:auto;}
        .column-chooser-header{padding:12px 16px;background-color:#f8f9fa;border-bottom:1px solid #ddd;font-weight:600;color:#293E4C;}
        .column-chooser-options{padding:8px 0;max-height:300px;overflow-y:auto;}
        .column-option{display:flex;align-items:center;padding:6px 16px;cursor:pointer;transition:background-color .2s ease;}
        .column-option:hover{background-color:#f8f9fa;}
        .column-option input[type=checkbox]{margin-right:8px;cursor:pointer;}
        .column-chooser-footer{padding:8px 16px;border-top:1px solid #ddd;background-color:#f8f9fa;}
        .reset-columns-btn{background-color:#6c757d;color:white;border:none;padding:4px 12px;border-radius:3px;cursor:pointer;font-size:.8em;transition:background-color .3s ease;}
        .reset-columns-btn:hover{background-color:#5a6268;}
        .column-hidden{display:none !important;}
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- ---------------- BREADCRUMB ------------------ -->
    <div class="breadcrumb">
        <?php foreach($breadcrumbs as $i=>$crumb): ?>
            <?php if(isset($crumb['href'])): ?>
                <a href="<?php echo $crumb['href']; ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
            <?php else: ?>
                <span><?php echo htmlspecialchars($crumb['text']); ?></span>
            <?php endif; ?>
            <?php if($i<count($breadcrumbs)-1): ?><span class="separator">&raquo;</span><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="container">
        <h1><?php echo $page_title_info; ?></h1>

        <!-- ------------- TIME FILTER HEADER ------------ -->
        <div class="time-filter-header">
            <div class="time-filters">
                <a href="?<?php echo http_build_query(array_merge($_GET,['time_filter'=>'all'])); ?>"
                   class="<?php echo $time_filter==='all'?'active':''; ?>">All</a>
                <a href="?<?php echo http_build_query(array_merge($_GET,['time_filter'=>'day','ref_date'=>$ref_date])); ?>"
                   class="<?php echo $time_filter==='day'?'active':''; ?>">Day</a>
                <a href="?<?php echo http_build_query(array_merge($_GET,['time_filter'=>'week','ref_date'=>$ref_date])); ?>"
                   class="<?php echo $time_filter==='week'?'active':''; ?>">Week</a>
                <a href="?<?php echo http_build_query(array_merge($_GET,['time_filter'=>'month','ref_date'=>$ref_date])); ?>"
                   class="<?php echo $time_filter==='month'?'active':''; ?>">Month</a>
            </div>

            <div class="date-navigation">
                <?php if($time_filter!=='all'): ?>
                    <button class="nav-arrow" onclick="location.href='?<?php echo http_build_query(array_merge($_GET,['ref_date'=>$prev_date])); ?>'">&larr;</button>
                <?php endif; ?>
                <span class="date-label"><?php echo $dateLabel; ?></span>
                <?php if($time_filter!=='all'): ?>
                    <button class="nav-arrow" onclick="location.href='?<?php echo http_build_query(array_merge($_GET,['ref_date'=>$next_date])); ?>'">&rarr;</button>
                <?php endif; ?>
            </div>

            <!-- Right‑side buttons -->
            <div class="right-filters">
                <div style="display:flex;gap:10px;align-items:center;">
                    <div class="filters-dropdown-container">
                        <!-- REMOVED inline onclick -->
                        <button id="filtersDropdownBtn" class="filters-dropdown-btn">🔽 Filters</button>
                        <div id="filtersDropdown" class="filters-dropdown-content" style="display:none;" onclick="preventDropdownClose(event)">
                            <div class="filters-dropdown-header">Filter Options:</div>

                            <div class="filter-item">
                                <label>Search in Table:</label>
                                <input type="text" id="searchInput" placeholder="Type to filter..." onkeyup="searchTable()">
                            </div>

                            <form id="filterForm" method="get">
                                <?php if($project_id): ?>
                                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                                <?php elseif($origin_batch_id): ?>
                                    <input type="hidden" name="origin_batch_id" value="<?php echo $origin_batch_id; ?>">
                                <?php endif; ?>
                                <input type="hidden" name="time_filter"   value="<?php echo htmlspecialchars($time_filter); ?>">
                                <input type="hidden" name="ref_date"     value="<?php echo htmlspecialchars($ref_date); ?>">

                                <div class="filter-item">
                                    <label for="status_filter">Filter by Status:</label>
                                    <select id="status_filter" name="status_filter" onchange="this.form.submit()">
                                        <?php
                                        $statuses = [''=> 'All',
                                            'Pending'=>'Pending',
                                            'In Transit to Warehouse'=>'In Transit to Warehouse',
                                            'Delivered to Warehouse'=>'Delivered to Warehouse',
                                            'In Transit to Project'=>'In Transit to Project',
                                            'Delivered to Project'=>'Delivered to Project',
                                            'Canceled'=>'Canceled'];
                                        foreach($statuses as $val=>$text){
                                            $sel = $status_filter===$val?'selected':'';
                                            echo "<option value=\"$val\" $sel>$text</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="filter-item">
                                    <span class="mobile-hide">
                                        <button type="submit" name="export" value="1" style="background:#488C9A;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">Export to CSV</button>
                                    </span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="column-chooser-container">
                        <!-- REMOVED inline onclick -->
                        <button id="columnChooserBtn" class="column-chooser-btn">📋 Choose Columns</button>
                        <div id="columnChooserDropdown" class="column-chooser-dropdown" style="display:none;">
                            <div class="column-chooser-header">Select Columns to Show:</div>
                            <div class="column-chooser-options">
                                <?php
                                $cols = [
                                    'supplier-column'=>"Supplier",
                                    'wattage-column'=>"Wattage",
                                    'status-column'=>"Status of Delivery",
                                    'quantity-column'=>"Quantity",
                                    'bol-column'=>"BOL Number",
                                    'anticipated-column'=>"Anticipated Delivery Date",
                                    'actual-column'=>"Actual Delivery Date",
                                    'pallets-column'=>"Associated Pallets",
                                    'scheduled-column'=>"Scheduled",
                                    'pod-column'=>"Proof of Delivery"];
                                foreach($cols as $cls=>$label){
                                    echo "<label class=\"column-option\"><input type=\"checkbox\" class=\"column-toggle\" data-column=\"$cls\" checked> $label</label>";
                                }
                                ?>
                            </div>
                            <div class="column-chooser-footer">
                                <button type="button" onclick="resetColumns()" class="reset-columns-btn">Reset to Default</button>
                            </div>
                        </div>
                    </div>

                    <?php if ($project_id): ?>
                    <div class="calendar-view-container">
                        <button type="button" class="calendar-view-btn" onclick="window.location.href='scheduling.php?project_id=<?php echo $project_id; ?>'">
                            📅 Calendar View
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ----------------- TABLE -------------------- -->
        <div class="table-responsive">
            <table id="deliveriesTable">
                <thead>
                    <tr>
                        <th class="supplier-column">Supplier</th>
                        <th class="wattage-column">Wattage</th>
                        <th class="status-column">Status of Delivery</th>
                        <th class="quantity-column">Quantity</th>
                        <th class="bol-column">BOL Number</th>
                        <th class="anticipated-column">Anticipated Delivery Date</th>
                        <th class="actual-column">Actual Delivery Date</th>
                        <th class="pallets-column">Associated Pallets</th>
                        <th class="scheduled-column">Scheduled</th>
                        <th class="pod-column">Proof of Delivery</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($deliveries): ?>
                    <?php
                    $palletConn=getDBConnection();
                    $stmtPallets=$palletConn->prepare("
                        SELECT ip.id,ip.pallet_identifier,ip.wattage,ip.quantity
                        FROM delivery_pallets dp
                        JOIN inventory_pallets ip ON dp.inventory_pallet_id=ip.id
                        WHERE dp.delivery_id = ?
                        ORDER BY ip.id");
                    ?>
                    <?php foreach($deliveries as $delivery): ?>
                        <?php
                        $stmtPallets->bind_param("i",$delivery['id']);
                        $stmtPallets->execute();
                        $palletRows=$stmtPallets->get_result()->fetch_all(MYSQLI_ASSOC);
                        $count=count($palletRows);
                        $palletData=json_encode($palletRows,JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_TAG|JSON_UNESCAPED_UNICODE);
                        ?>
                        <tr <?php if($highlight_delivery_id==$delivery['id']) echo 'class="highlighted-delivery" id="highlighted-delivery"'; ?> data-delivery-id="<?php echo $delivery['id']; ?>">
                            <td class="supplier-column"><?php echo htmlspecialchars($delivery['supplier']); ?></td>
                            <td class="wattage-column"><?php echo htmlspecialchars($delivery['wattage']); ?></td>
                            <td class="status-column"><?php echo htmlspecialchars($delivery['status_of_delivery']); ?></td>
                            <td class="quantity-column"><?php echo htmlspecialchars($delivery['quantity']); ?></td>
                            <td class="bol-column"><?php echo htmlspecialchars($delivery['bol_number']); ?></td>
                            <td class="anticipated-column"><?php echo $delivery['anticipated_delivery_date']?date('m-d-Y',strtotime($delivery['anticipated_delivery_date'])):''; ?></td>
                            <td class="actual-column"><?php echo $delivery['actual_delivery_date']?date('m-d-Y',strtotime($delivery['actual_delivery_date'])):''; ?></td>
                            <td class="pallets-column">
                                <?php if($count): ?>
                                    <!-- REMOVED inline onclick -->
                                    <button type="button" class="action-buttons view-pallets-btn"
                                            data-pallets='<?php echo htmlspecialchars($palletData,ENT_QUOTES); ?>'
                                            style="background:#488C9A;color:#fff;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;">
                                        View Pallets (<?php echo $count; ?>)
                                    </button>
                                <?php else: ?>N/A<?php endif; ?>
                            </td>
                            <td class="scheduled-column">
                                <?php if ($delivery['scheduled'] == 1): ?>
                                    <?php if (!empty($delivery['project_id']) && !empty($delivery['appointment_id'])): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>&appointment_id=<?php echo $delivery['appointment_id']; ?>&auto_edit=1" 
                                           style="color: #488C9A; text-decoration: underline;">View Appointment</a>
                                    <?php else: ?>
                                        <span style="color: #28a745;">Scheduled</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!empty($delivery['project_id']) && $delivery['status_of_delivery'] === 'In Transit to Project'): ?>
                                        <a href="scheduling.php?project_id=<?php echo $delivery['project_id']; ?>&delivery_id=<?php echo $delivery['id']; ?>" 
                                           style="color: #fbb040; text-decoration: underline;">Schedule Delivery</a>
                                    <?php else: ?>
                                        <span style="color: #666;">N/A</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="pod-column">
                                <?php if($delivery['proof_of_delivery']): ?>
                                    <a href="view_pod?delivery_id=<?php echo $delivery['id']; ?>" target="_blank">View POD</a>
                                <?php elseif($role==='global_admin'): ?>
                                    <a href="upload_pod?delivery_id=<?php echo $delivery['id']; ?>">Upload POD</a>
                                <?php else: ?>N/A<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php $stmtPallets->close(); $palletConn->close(); ?>
                <?php else: ?>
                    <tr id="no-entries-row"><td colspan="9">No delivery entries found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- -------- Modal -------- -->
    <div id="associatedPalletsModal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;overflow:auto;background:rgba(0,0,0,.5);">
        <div style="background:#fff;margin:5% auto;padding:20px;width:500px;max-width:90%;border-radius:8px;box-shadow:0 4px 8px rgba(0,0,0,.2);position:relative;">
            <!-- REMOVED inline onclick -->
            <span id="closePalletModalBtn" style="position:absolute;top:15px;right:20px;font-size:24px;font-weight:bold;color:#aaa;cursor:pointer;">&times;</span>
            <h2>Associated Pallets</h2>
            <div id="palletList" style="max-height:300px;overflow-y:auto;border:1px solid #eee;"></div>
        </div>
    </div>
</main>

<script>
/* ======= simple utilities & state =======*/
var associatedPalletsModal, palletListDiv;

/* --------------- search -----------------*/
function searchTable(){
    var input=document.getElementById('searchInput');
    if(!input) return;
    var fl=input.value.toLowerCase();
    Array.from(document.querySelectorAll('#deliveriesTable tbody tr')).forEach((tr,i)=>{
        if(i===0) return; // skip header
        var show=false;
        tr.querySelectorAll('td').forEach(td=>{
            if(td.textContent.toLowerCase().indexOf(fl)>-1) show=true;
        });
        tr.style.display=show?'':'none';
    });
}

/* --------------- dropdowns --------------*/
function toggleFiltersDropdown(){
    const f=document.getElementById('filtersDropdown');
    f.style.display=f.style.display==='none'?'block':'none';
    document.getElementById('columnChooserDropdown').style.display='none';
}
function toggleColumnChooser(){
    const c=document.getElementById('columnChooserDropdown');
    c.style.display=c.style.display==='none'?'block':'none';
    document.getElementById('filtersDropdown').style.display='none';
}
function toggleColumn(col,show){
    document.querySelectorAll('.'+col).forEach(el=>{
        show?el.classList.remove('column-hidden'):el.classList.add('column-hidden');
    });
    const row=document.getElementById('no-entries-row');
    if(row){
        row.querySelector('td').colSpan=document.querySelectorAll('th:not(.column-hidden)').length;
    }
}
function resetColumns(){
    document.querySelectorAll('.column-toggle').forEach(cb=>{
        cb.checked=true; toggleColumn(cb.dataset.column,true);
    });
    saveColumnPreferences();
}
function saveColumnPreferences(){
    var prefs={};
    document.querySelectorAll('.column-toggle').forEach(cb=>{
        prefs[cb.dataset.column]=cb.checked;
    });
    localStorage.setItem('viewProjectColumnPreferences',JSON.stringify(prefs));
}
function loadColumnPreferences(){
    var p=localStorage.getItem('viewProjectColumnPreferences');
    if(!p) return;
    p=JSON.parse(p);
    document.querySelectorAll('.column-toggle').forEach(cb=>{
        if(p.hasOwnProperty(cb.dataset.column)){
            cb.checked=p[cb.dataset.column];
            toggleColumn(cb.dataset.column,cb.checked);
        }
    });
}
function preventDropdownClose(e){e.stopPropagation();}

/* ---------------- Modal -----------------*/
function showPalletModal(btn){
    if(!associatedPalletsModal){
        associatedPalletsModal=document.getElementById('associatedPalletsModal');
        palletListDiv=document.getElementById('palletList');
    }
    palletListDiv.innerHTML='';
    const pallets=JSON.parse(btn.dataset.pallets||'[]');
    if(!pallets.length){palletListDiv.textContent='No pallets found.';}
    else{
        const tbl=document.createElement('table'); tbl.style.width='100%'; tbl.style.borderCollapse='collapse';
        const head=tbl.createTHead().insertRow();
        ['Identifier','Wattage','Quantity','Actions'].forEach(h=>{
            const th=document.createElement('th');
            th.textContent=h; th.style.border='1px solid #ddd'; th.style.padding='8px'; th.style.textAlign='center';
            th.style.background='#293E4C'; th.style.color='#fff'; head.appendChild(th);
        });
        const body=tbl.createTBody();
        pallets.forEach(p=>{
            const r=body.insertRow();
            const id  =r.insertCell(); id.textContent=p.pallet_identifier||`ID: ${p.id}`;
            const wat =r.insertCell(); wat.textContent=p.wattage?`${p.wattage}W`:'N/A';
            const qty =r.insertCell(); qty.textContent=p.quantity||'N/A';
            [id,wat,qty].forEach(c=>{c.style.border='1px solid #ddd'; c.style.padding='8px';});
            const act =r.insertCell(); act.style.border='1px solid #ddd'; act.style.padding='8px'; act.style.textAlign='center';
            const a=document.createElement('a'); a.href=`pallet_details.php?pallet_id=${p.id}`; a.textContent='View Details';
            a.style.color='#488C9A'; a.style.textDecoration='none'; act.appendChild(a);
        });
        palletListDiv.appendChild(tbl);
    }
    associatedPalletsModal.style.display='block';
}
function closeAssociatedPalletModal(){
    associatedPalletsModal.style.display='none';
    palletListDiv.innerHTML='';
}

/* --------------- DOM ready --------------*/
document.addEventListener('DOMContentLoaded',()=>{
    loadColumnPreferences();
    document.getElementById('filtersDropdownBtn')?.addEventListener('click',toggleFiltersDropdown);
    document.getElementById('columnChooserBtn')?.addEventListener('click',toggleColumnChooser);
    document.querySelectorAll('.view-pallets-btn').forEach(btn=>{
        btn.addEventListener('click',function(){showPalletModal(this);});
    });
    document.getElementById('closePalletModalBtn')?.addEventListener('click',closeAssociatedPalletModal);

    document.querySelectorAll('.column-toggle').forEach(cb=>{
        cb.addEventListener('change',()=>{toggleColumn(cb.dataset.column,cb.checked); saveColumnPreferences();});
    });
    document.addEventListener('click',e=>{
        if(!document.querySelector('.column-chooser-container').contains(e.target))
            document.getElementById('columnChooserDropdown').style.display='none';
        if(!document.querySelector('.filters-dropdown-container').contains(e.target))
            document.getElementById('filtersDropdown').style.display='none';
    });
    window.addEventListener('click',e=>{
        if(e.target===associatedPalletsModal) closeAssociatedPalletModal();
    });

    const hi=document.getElementById('highlighted-delivery');
    if(hi){
        setTimeout(()=>{
            hi.scrollIntoView({behavior:'smooth',block:'center'});
            hi.style.animation='highlightPulse 3s ease-in-out 2';
            setTimeout(()=>{hi.style.animation='fadeOutHighlight 2s ease-in-out forwards';},12000);
        },800);
    }
});
</script>
</body>
</html>
