<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'total';
$current_year = date('Y');

if ($role === 'global_admin') {
    $sql_projects = "SELECT p.id, p.project_name, p.image_url FROM projects p WHERE status IS NULL OR status = 'active'";
    $params = [];
    $paramTypes = "";
} else {
    $sql_projects = "SELECT p.id, p.project_name, p.image_url FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE cau.user_id = ? AND (p.status IS NULL OR p.status = 'active')";
    $params = [$user_id];
    $paramTypes = "i";
}

$stmt_projects = $conn->prepare($sql_projects);
if (!empty($paramTypes)) {
    $stmt_projects->bind_param($paramTypes, ...$params);
}
$stmt_projects->execute();
$projects_result = $stmt_projects->get_result();
$stmt_projects->close();

$project_count = $projects_result->num_rows;

$total_emissions = 0;
$total_truckloads = 0;
$total_miles_driven = 0;
$total_fuel_consumption = 0;

$projects = [];

while ($project = $projects_result->fetch_assoc()) {
    $project_id = $project['id'];
    $project_total_emissions = 0;
    $project_total_truckloads = 0;
    $project_miles_driven = 0;
    $project_fuel_consumption = 0;

    $sql_deliveries = "SELECT * FROM deliveries WHERE project_id = ?";
    if ($filter === 'ytd') {
        $sql_deliveries .= " AND YEAR(created_at) = ?";
    }

    $stmt_deliveries = $conn->prepare($sql_deliveries);
    if ($filter === 'ytd') {
        $stmt_deliveries->bind_param("ii", $project_id, $current_year);
    } else {
        $stmt_deliveries->bind_param("i", $project_id);
    }
    $stmt_deliveries->execute();
    $deliveries_result = $stmt_deliveries->get_result();
    $stmt_deliveries->close();

    while ($delivery = $deliveries_result->fetch_assoc()) {
        if (in_array($delivery['status_of_delivery'] ?? '', ['Delivered to Project', 'Delivered to Warehouse'])) {
            $project_total_truckloads += 1;
        }

        $miles_driven = (float)($delivery['miles'] ?? 0);
        $project_miles_driven += $miles_driven;

        $fuel_consumption = $miles_driven * 0.1667;
        $project_fuel_consumption += $fuel_consumption;

        $emissions = $fuel_consumption * 10.21;
        $project_total_emissions += $emissions;
    }

    $total_emissions += $project_total_emissions;
    $total_truckloads += $project_total_truckloads;
    $total_miles_driven += $project_miles_driven;
    $total_fuel_consumption += $project_fuel_consumption;

    $project['total_emissions'] = $project_total_emissions;
    $project['total_truckloads'] = $project_total_truckloads;
    $project['miles_driven'] = $project_miles_driven;
    $project['fuel_consumption'] = $project_fuel_consumption;

    $projects[] = $project;
}

if ($filter === 'per_project' && $project_count > 0) {
    $display_emissions = $total_emissions / $project_count;
    $display_truckloads = $total_truckloads / $project_count;
    $display_miles = $total_miles_driven / $project_count;
    $display_fuel = $total_fuel_consumption / $project_count;
} else {
    $display_emissions = $total_emissions;
    $display_truckloads = $total_truckloads;
    $display_miles = $total_miles_driven;
    $display_fuel = $total_fuel_consumption;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sustainability Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .page-header {
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: visible;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header-content { display: flex; align-items: flex-start; gap: 12px; }
        .page-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
        }
        .page-header p { color: #6c757d; font-size: 1.1em; margin: 0; }
        .info-tooltip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px; height: 22px;
            background: #488C9A;
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            position: relative;
            font-size: 0.7em;
            flex-shrink: 0;
            margin-top: 8px;
        }
        .info-tooltip:hover { background: #3A6E7F; }
        .info-tooltip .tooltip-text {
            display: none;
            width: 320px;
            background: #fff;
            color: #333;
            text-align: left;
            border-radius: 8px;
            padding: 16px;
            position: absolute;
            z-index: 1000;
            top: 30px;
            left: -150px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            font-weight: normal;
            font-size: 0.85rem;
            line-height: 1.5;
        }
        .info-tooltip .tooltip-text p { margin: 0 0 8px; font-weight: 600; }
        .info-tooltip .tooltip-text ul { margin: 0; padding-left: 18px; }
        .info-tooltip .tooltip-text li { margin-bottom: 4px; }
        .info-tooltip:hover .tooltip-text { display: block; }

        .filter-pills {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
            margin-bottom: 28px;
        }
        .filter-pill {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 600;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-pill:hover { background: rgba(255,255,255,0.5); color: #293E4C; }
        .filter-pill.active { background: #fff; color: #293E4C; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
        .stat-card.primary { background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); border: none; }
        .stat-card.primary .stat-label, .stat-card.primary .stat-value { color: #fff; }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.5em;
            background: linear-gradient(135deg, #e8f4f7 0%, #d4eef3 100%);
        }
        .stat-card.primary .stat-icon { background: rgba(255,255,255,0.2); }
        .stat-value { font-size: 1.8em; font-weight: 700; color: #293E4C; margin-bottom: 4px; }
        .stat-label { color: #6c757d; font-size: 0.85em; font-weight: 500; }

        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .section-title-group { display: flex; align-items: center; gap: 16px; }
        .section-title {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            letter-spacing: -0.3px;
        }
        .section-subtitle { font-size: 0.85em; color: #6c757d; margin: 4px 0 0; }
        .view-toggle {
            display: inline-flex;
            background: #f1f3f4;
            border-radius: 8px;
            padding: 3px;
            gap: 3px;
        }
        .view-toggle button {
            padding: 8px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 1em;
            border: none;
            cursor: pointer;
            color: #6c757d;
            background: transparent;
            transition: all 0.2s;
        }
        .view-toggle button:hover { color: #293E4C; background: rgba(255,255,255,0.5); }
        .view-toggle button.active { background: #fff; color: #293E4C; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            max-width: 1400px;
        }
        .projects-grid:not(.active) { display: none; }
        @media (min-width: 1400px) { .projects-grid { grid-template-columns: repeat(4, 1fr); } }

        .project-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.2s;
            cursor: pointer;
        }
        .project-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,0.12); }
        .project-card-image {
            width: 100%; height: 120px;
            background: linear-gradient(135deg, #e8f4f7 0%, #d4eef3 100%);
            position: relative;
            overflow: hidden;
        }
        .project-card-image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(45deg, rgba(72,140,154,0.9), rgba(58,110,127,0.9));
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .project-card:hover .project-card-overlay { opacity: 1; }
        .project-card-overlay span { color: #fff; font-size: 0.95em; font-weight: 600; }
        .project-card-content { padding: 16px; }
        .project-card-title { margin: 0 0 16px; font-size: 1em; color: #293E4C; font-weight: 600; line-height: 1.3; }
        .project-card-stats { display: flex; flex-direction: column; gap: 8px; }
        .project-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .project-stat-row .label { font-size: 0.8em; color: #6c757d; }
        .project-stat-row .value { font-size: 0.9em; font-weight: 700; color: #488C9A; }
        .project-card-highlight {
            margin-top: 12px;
            padding: 14px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 10px;
            text-align: center;
        }
        .project-card-highlight .value { font-size: 1.2em; font-weight: 700; color: #fff; }
        .project-card-highlight .label { font-size: 0.75em; color: rgba(255,255,255,0.85); margin-top: 2px; }

        .table-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            display: none;
        }
        .table-container.active { display: block; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: #488C9A;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: #fff;
            font-size: 0.8em;
            border-bottom: none;
        }
        .data-table td { padding: 14px 16px; border-bottom: 1px solid #f1f3f4; font-size: 0.9em; }
        .data-table tbody tr { cursor: pointer; transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f8f9fa; }
        .data-table .project-name { font-weight: 600; color: #293E4C; }
        .data-table .metric-cell { font-weight: 600; color: #488C9A; }
        .data-table .highlight-cell {
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 6px;
            padding: 6px 12px;
            display: inline-block;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state h3 { color: #293E4C; margin-bottom: 8px; }

        @media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .page-header { padding: 24px; }
            .page-header h1 { font-size: 1.8em; }
            .stats-grid { grid-template-columns: 1fr; }
            .projects-grid { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            .data-table { min-width: 700px; }
            .filter-pills { flex-wrap: wrap; justify-content: center; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Sustainability Overview']); ?>

    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1>Sustainability Overview</h1>
                <p>Environmental impact tracking and carbon footprint analysis</p>
            </div>
            <span class="info-tooltip">?
                <span class="tooltip-text">
                    <p>Calculations:</p>
                    <ul>
                        <li>6 miles per gallon for heavy-duty freight trucks (US DOE)</li>
                        <li>Fuel consumption: ~0.1667 gallons/mile</li>
                        <li>Diesel emissions: ~10.21 kg CO2/gallon (EPA)</li>
                    </ul>
                </span>
            </span>
        </div>
    </div>

    <div class="filter-pills">
        <button type="button" class="filter-pill <?php echo $filter === 'total' ? 'active' : ''; ?>" data-filter="total">All Time</button>
        <button type="button" class="filter-pill <?php echo $filter === 'ytd' ? 'active' : ''; ?>" data-filter="ytd">Year to Date</button>
        <button type="button" class="filter-pill <?php echo $filter === 'per_project' ? 'active' : ''; ?>" data-filter="per_project">Avg per Project</button>
    </div>

    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon">🌱</div>
            <div class="stat-value"><?php echo number_format($display_emissions, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg CO2 (kg)' : 'Total CO2 Emissions (kg)'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚛</div>
            <div class="stat-value"><?php echo number_format($display_truckloads, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Truckloads' : 'Total Truckloads'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🛣️</div>
            <div class="stat-value"><?php echo number_format($display_miles, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Miles' : 'Total Miles Driven'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⛽</div>
            <div class="stat-value"><?php echo number_format($display_fuel, 0); ?></div>
            <div class="stat-label"><?php echo $filter === 'per_project' ? 'Avg Fuel (gal)' : 'Total Fuel (gallons)'; ?></div>
        </div>
    </div>

    <?php if (!empty($projects)): ?>
    <div class="section-header-row">
        <div class="section-title-group">
            <div>
                <h2 class="section-title">Environmental Impact by Project</h2>
                <p class="section-subtitle"><?php echo $project_count; ?> active project<?php echo $project_count !== 1 ? 's' : ''; ?> with sustainability data</p>
            </div>
        </div>
        <div class="view-toggle">
            <button type="button" class="active" id="btn-grid" title="Grid View">▦</button>
            <button type="button" id="btn-table" title="Table View">☰</button>
        </div>
    </div>

    <div class="projects-grid active" id="projects-grid">
        <?php foreach ($projects as $proj): ?>
        <div class="project-card" data-href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
            <div class="project-card-image">
                <?php if (!empty($proj['image_url'])): ?>
                <img src="<?php echo htmlspecialchars($proj['image_url']); ?>" alt="<?php echo htmlspecialchars($proj['project_name']); ?>">
                <?php endif; ?>
                <div class="project-card-overlay"><span>View Details</span></div>
            </div>
            <div class="project-card-content">
                <h3 class="project-card-title"><?php echo htmlspecialchars($proj['project_name']); ?></h3>
                <div class="project-card-stats">
                    <div class="project-stat-row">
                        <span class="label">Truckloads</span>
                        <span class="value"><?php echo number_format($proj['total_truckloads'], 0); ?></span>
                    </div>
                    <div class="project-stat-row">
                        <span class="label">Miles Driven</span>
                        <span class="value"><?php echo number_format($proj['miles_driven'], 0); ?> mi</span>
                    </div>
                    <div class="project-stat-row">
                        <span class="label">Fuel Used</span>
                        <span class="value"><?php echo number_format($proj['fuel_consumption'], 0); ?> gal</span>
                    </div>
                </div>
                <div class="project-card-highlight">
                    <div class="value"><?php echo number_format($proj['total_emissions'], 0); ?> kg</div>
                    <div class="label">CO2 Emissions</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="table-container" id="projects-table">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Truckloads</th>
                    <th>Miles Driven</th>
                    <th>Fuel (gallons)</th>
                    <th>CO2 Emissions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): ?>
                <tr data-href="project_sustainability_details?project_id=<?php echo $proj['id']; ?>">
                    <td class="project-name"><?php echo htmlspecialchars($proj['project_name']); ?></td>
                    <td class="metric-cell"><?php echo number_format($proj['total_truckloads'], 0); ?></td>
                    <td class="metric-cell"><?php echo number_format($proj['miles_driven'], 0); ?> mi</td>
                    <td class="metric-cell"><?php echo number_format($proj['fuel_consumption'], 0); ?> gal</td>
                    <td><span class="highlight-cell"><?php echo number_format($proj['total_emissions'], 0); ?> kg</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <h3>No Projects Found</h3>
        <p>No projects with sustainability data are available.</p>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filter pills
    document.querySelectorAll('.filter-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var filter = this.getAttribute('data-filter');
            var url = new URL(window.location.href);
            url.searchParams.set('filter', filter);
            window.location.href = url.toString();
        });
    });

    // Grid/Table view toggle
    var btnGrid = document.getElementById('btn-grid');
    var btnTable = document.getElementById('btn-table');
    var gridView = document.getElementById('projects-grid');
    var tableView = document.getElementById('projects-table');

    function setView(view) {
        if (gridView) gridView.classList.toggle('active', view === 'grid');
        if (tableView) tableView.classList.toggle('active', view === 'table');
        if (btnGrid) btnGrid.classList.toggle('active', view === 'grid');
        if (btnTable) btnTable.classList.toggle('active', view === 'table');
        localStorage.setItem('sustainabilityView', view);
    }

    if (btnGrid) btnGrid.addEventListener('click', function() { setView('grid'); });
    if (btnTable) btnTable.addEventListener('click', function() { setView('table'); });

    // Restore saved view
    var savedView = localStorage.getItem('sustainabilityView') || 'grid';
    setView(savedView);

    // Card and row clicks
    document.querySelectorAll('[data-href]').forEach(function(el) {
        el.addEventListener('click', function() {
            window.location.href = this.getAttribute('data-href');
        });
    });
});
</script>
</body>
</html>
