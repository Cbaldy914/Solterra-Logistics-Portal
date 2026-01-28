<?php
session_name("logistics_session");
session_start();

// ========== AUTHENTICATION & PERMISSIONS ==========
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Database connection
require_once '../config.php';
require_once 'projection_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// ========== AJAX HANDLERS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Handle PO Execution Date save
    if ($action === 'save_po_execution_date') {
        header('Content-Type: application/json');

        if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            exit();
        }

        $allocation_id = intval($_POST['allocation_id'] ?? 0);
        $po_execution_date = $_POST['po_execution_date'] ?? '';

        if (!$allocation_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid allocation ID']);
            exit();
        }

        // Validate date format
        $date_value = null;
        if (!empty($po_execution_date)) {
            $date = DateTime::createFromFormat('Y-m-d', $po_execution_date);
            if ($date) {
                $date_value = $date->format('Y-m-d');
            }
        }

        // Update the allocation with PO execution date
        $stmt = $conn->prepare("UPDATE projection_module_allocations SET po_execution_date = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $date_value, $allocation_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'PO execution date saved']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }

        $conn->close();
        exit();
    }
}

// ========== PORTFOLIO MODE (No project_id) ==========
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    // Only admin, global_admin, customer_admin can access portfolio view
    if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
        header("Location: dashboard");
        exit();
    }

    // Get user's accounts
    $accountIds = [];
    if ($role === 'global_admin') {
        $resultAccts = $conn->query("SELECT id FROM customer_accounts");
        if ($resultAccts) {
            while ($row = $resultAccts->fetch_assoc()) {
                $accountIds[] = (int)$row['id'];
            }
        }
    } else {
        $stmtAccts = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
        $stmtAccts->bind_param("i", $user_id);
        $stmtAccts->execute();
        $resultAccts = $stmtAccts->get_result();
        while ($row = $resultAccts->fetch_assoc()) {
            $accountIds[] = (int)$row['account_id'];
        }
        $stmtAccts->close();
    }

    // Get projects
    $projects = [];
    if (count($accountIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $conn->prepare("
            SELECT p.id, p.project_name, p.project_address, p.image_url, p.estimated_completion_date,
                   ca.name as account_name
            FROM projects p
            JOIN customer_accounts ca ON p.account_id = ca.id
            WHERE p.account_id IN ($placeholders)
            AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Check if projection exists
            $row['has_projection'] = project_has_projection($conn, $row['id']);
            $projects[] = $row;
        }
        $stmt->close();
    }

    $conn->close();
    include 'components/anticipated_deliveries_portfolio.php';
    exit();
}

$project_id = intval($_GET['project_id']);

// ========== ACCESS CONTROL ==========
if ($role === 'admin') {
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
} elseif ($role === 'global_admin') {
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $project_id);
} else {
    $stmt = $conn->prepare("
        SELECT p.*, ca.name as account_name
        FROM projects p
        JOIN customer_accounts ca ON p.account_id = ca.id
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("You do not have access to this project.");
}

$project = $result->fetch_assoc();
$stmt->close();

// Determine if user can edit
$can_edit = in_array($role, ['admin', 'global_admin', 'customer_admin']);

// ========== FETCH PROJECT DATA ==========
$projections = get_project_projections($conn, $project_id);
$available_batches = get_available_module_batches($conn, $project_id);

// Check if specific projection_id is requested
$requested_projection_id = isset($_GET['projection_id']) ? intval($_GET['projection_id']) : null;

// Load the requested projection or fall back to primary
if ($requested_projection_id) {
    $current_projection = get_projection($conn, $requested_projection_id);
} else {
    $current_projection = get_primary_projection($conn, $project_id);
}

// Prepare data for initial load
$allocated_modules = $current_projection['module_allocations'] ?? [];
$stops = $current_projection['stops'] ?? [];
$legs = $current_projection['legs'] ?? [];
$cost_summary = $current_projection['cost_summary'] ?? [];
$total_pallets = 0;
$total_modules = 0;
$total_contract_value = 0;
foreach ($allocated_modules as $alloc) {
    $total_pallets += $alloc['pallets'] ?? 0;
    $total_modules += $alloc['quantity'] ?? 0;
    $total_contract_value += $alloc['contract_value'] ?? 0;
}

// Get project size summary for header stats
require_once 'anticipated_schedule_helpers.php';
$project_summary = getProjectSizeSummary($conn, $project_id);

// Manufacturer suggestions for manual entry
$manufacturer_names = [];
$manufacturer_locations = [];
$manufacturer_location_map = [];
if (!empty($project['account_id'])) {
    $stmt = $conn->prepare("
        SELECT vendor_name, initial_location
        FROM modules
        WHERE account_id = ?
          AND vendor_name IS NOT NULL
          AND vendor_name <> ''
    ");
    if ($stmt) {
        $stmt->bind_param("i", $project['account_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $name_set = [];
        $location_set = [];
        $location_map = [];
        while ($row = $result->fetch_assoc()) {
            $vendor = trim($row['vendor_name'] ?? '');
            $location = trim($row['initial_location'] ?? '');
            if ($vendor !== '') {
                $name_set[$vendor] = true;
            }
            if ($location !== '') {
                $location_set[$location] = true;
                if ($vendor !== '') {
                    if (!isset($location_map[$vendor])) {
                        $location_map[$vendor] = [];
                    }
                    $location_map[$vendor][$location] = true;
                }
            }
        }
        $stmt->close();

        $manufacturer_names = array_keys($name_set);
        sort($manufacturer_names, SORT_NATURAL | SORT_FLAG_CASE);

        $manufacturer_locations = array_keys($location_set);
        sort($manufacturer_locations, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($location_map as $vendor => $locations) {
            $locations_list = array_keys($locations);
            sort($locations_list, SORT_NATURAL | SORT_FLAG_CASE);
            $manufacturer_location_map[$vendor] = $locations_list;
        }
    }
}

// Note: Don't close connection here - components may need it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery & Cost Planning - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php
    $google_maps_key = getenv('GOOGLE_MAPS_API_KEY') ?: '';
    if (!empty($google_maps_key)):
    ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo $google_maps_key; ?>&libraries=geometry,places"></script>
    <?php endif; ?>
    <?php include 'components/anticipated_deliveries_styles.php'; ?>

</head>
<body>
    <?php include 'header.php'; ?>

    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs([
                'current_label' => 'Delivery & Cost Planning',
                'project_id' => $project_id
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                    </div>
                    <div class="header-info">
                        <h1>Delivery & Cost Planning</h1>
                        <p class="header-subtitle">
                            <a href="view_project.php?id=<?php echo $project_id; ?>"><?php echo htmlspecialchars($project['project_name']); ?></a>
                            <span style="color: #dee2e6;">|</span>
                            <?php echo htmlspecialchars($project['account_name']); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$can_edit): ?>
        <div class="readonly-banner">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <div>
                <strong>View Only Mode</strong>
                You can view delivery projections, but only administrators can create or modify them.
            </div>
        </div>
        <?php endif; ?>

        <!-- Projection Header Component -->
        <?php
            include 'components/projection_header.php';
        ?>

        <?php if ($can_edit && !$current_projection): ?>
        <!-- Empty State - No Projection Yet -->
        <div class="empty-projection">
            <div class="empty-projection-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="3" width="15" height="13"/>
                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                    <circle cx="5.5" cy="18.5" r="2.5"/>
                    <circle cx="18.5" cy="18.5" r="2.5"/>
                </svg>
            </div>
            <h3>Plan Your Delivery Journey</h3>
            <p>Create a projection to plan the complete delivery route from manufacturer to jobsite, including warehouse stops, shipping legs, and cost tracking.</p>
            <button type="button" class="btn btn-primary" onclick="createNewProjection()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create New Projection
            </button>
            <div class="getting-started-steps">
                <div class="getting-started-step">
                    <span class="step-number">1</span>
                    <span>Add module batches</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">2</span>
                    <span>Plan warehouse stops</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">3</span>
                    <span>Configure shipping legs</span>
                </div>
                <div class="getting-started-step">
                    <span class="step-number">4</span>
                    <span>Review costs & milestones</span>
                </div>
            </div>
        </div>
        <?php else: ?>

        <!-- Step Navigation -->
        <div class="stepper-nav" id="stepperNav">
            <div class="stepper-step active" data-step="modules-costs" onclick="navigateToStep('modules-costs')">
                <span class="stepper-number">1</span>
                <span class="stepper-label">Modules</span>
            </div>
            <div class="stepper-connector"></div>
            <div class="stepper-step" data-step="logistics-plan" onclick="navigateToStep('logistics-plan')">
                <span class="stepper-number">2</span>
                <span class="stepper-label">Logistics & Map</span>
            </div>
            <div class="stepper-connector"></div>
            <div class="stepper-step" data-step="timeline" onclick="navigateToStep('timeline')">
                <span class="stepper-number">3</span>
                <span class="stepper-label">Costs</span>
            </div>
        </div>

        <!-- Main Content Layout -->
        <div class="planner-layout">
            <div class="planner-main">
                <!-- Collapsible: Modules & Manufacturers -->
                <div class="collapsible-section" data-section="modules-costs">
                    <div class="collapsible-header" onclick="toggleSection('modules-costs')">
                        <div class="collapsible-title">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                <line x1="8" y1="21" x2="16" y2="21"/>
                                <line x1="12" y1="17" x2="12" y2="21"/>
                            </svg>
                            Modules & Manufacturers
                        </div>
                        <div class="collapsible-meta">
                            <div class="card-summary">
                                <span class="summary-value" id="totalModulesCount"><?php echo number_format($total_modules); ?></span>
                                <span class="summary-label">modules</span>
                                <span class="summary-divider">|</span>
                                <span class="summary-value" id="totalContractValue">$<?php echo number_format($total_contract_value, 2); ?></span>
                                <span class="summary-label">contract value</span>
                            </div>
                            <span class="collapsible-badge" id="modulesBadge"><?php echo number_format($total_pallets); ?> pallets</span>
                            <div class="collapsible-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="collapsible-content" id="modules-costs-content">
                        <div class="collapsible-inner">
                            <!-- Module Selector Component -->
                            <?php include 'components/projection_module_selector.php'; ?>
                        </div>
                    </div>
                </div>

                <!-- Collapsible: Logistics Plan (includes Map View) -->
                <div class="collapsible-section" data-section="logistics-plan">
                    <div class="collapsible-header" onclick="toggleSection('logistics-plan')">
                        <div class="collapsible-title">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 2a10 10 0 0 1 0 20"/>
                                <path d="M12 8v4l3 3"/>
                            </svg>
                            Logistics Plan
                        </div>
                        <div class="collapsible-meta">
                            <!-- Simplified route summary -->
                            <div class="collapsed-summary" id="logisticsCollapsedSummary">
                                <span class="summary-text" id="logisticsRouteText">No route configured</span>
                                <span class="summary-divider">|</span>
                                <span class="summary-highlight" id="logisticsTotalCost">$0 total logistics</span>
                            </div>
                            <div class="collapsible-toggle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </div>
                    </div>
                    <div class="collapsible-content" id="logistics-plan-content">
                        <!-- View Toggle: Journey / Map -->
                        <div class="logistics-view-toggle">
                            <button type="button" class="view-toggle-btn active" data-view="journey" onclick="switchLogisticsView('journey')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v4l3 3"/>
                                </svg>
                                Journey Plan
                            </button>
                            <button type="button" class="view-toggle-btn" data-view="map" onclick="switchLogisticsView('map')">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                                    <line x1="8" y1="2" x2="8" y2="18"/>
                                    <line x1="16" y1="6" x2="16" y2="22"/>
                                </svg>
                                Map View
                            </button>
                        </div>

                        <!-- Journey View - Flow Canvas -->
                        <div class="logistics-view active" id="logistics-journey-view">
                            <div class="journey-flow-container">
                                <!-- Instruction Banner -->
                                <div class="journey-flow-hint">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 16v-4"/>
                                        <path d="M12 8h.01"/>
                                    </svg>
                                    <span>Click a location's <strong>connect button</strong> to start drawing a route, then click on the <strong>receive port</strong> of the destination. Add warehouses or ports between origin and destination.</span>
                                </div>

                                <!-- Journey Flow Layout -->
                                <div class="journey-flow-layout" id="journeyFlowLayout">
                                    <!-- ORIGIN COLUMN (Manufacturers) -->
                                    <div class="journey-column journey-origin-column">
                                        <div class="column-header">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                                                <polyline points="9 22 9 12 15 12 15 22"/>
                                            </svg>
                                            <span>Origin</span>
                                        </div>
                                        <div class="journey-nodes" id="journeyOriginNodes">
                                            <!-- Origin nodes rendered by JS -->
                                        </div>
                                    </div>

                                    <!-- INTERMEDIATE STOPS (Warehouses/Ports) - Scrollable row -->
                                    <div class="journey-column journey-stops-column">
                                        <div class="column-header">
                                            <div class="column-header-left">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                                                </svg>
                                                <span>Intermediate Stops</span>
                                                <span class="column-subtext">(warehouses, ports, storage)</span>
                                            </div>
                                            <button type="button" class="journey-add-stop-btn" id="journeyAddStopBtn" onclick="openAddStopModal()">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                                </svg>
                                                <span>Add Stop</span>
                                            </button>
                                        </div>
                                        <div class="journey-stops-wrapper">
                                            <div class="journey-stops-scroll" id="journeyStopsScroll">
                                                <!-- Stops rendered by JS, up to 5 -->
                                            </div>
                                        </div>
                                    </div>

                                    <!-- DESTINATION COLUMN (Project Site) -->
                                    <div class="journey-column journey-destination-column">
                                        <div class="column-header">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                                <circle cx="12" cy="10" r="3"/>
                                            </svg>
                                            <span>Destination</span>
                                        </div>
                                        <div class="journey-nodes" id="journeyDestinationNodes">
                                            <!-- Destination node rendered by JS -->
                                        </div>
                                    </div>

                                    <!-- SVG Overlay for Connection Lines -->
                                    <svg class="journey-connections-svg" id="journeyConnectionsSvg">
                                        <defs>
                                            <linearGradient id="legGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                                <stop offset="0%" style="stop-color:rgba(72,140,154,0.9)"/>
                                                <stop offset="100%" style="stop-color:rgba(72,140,154,0.5)"/>
                                            </linearGradient>
                                            <marker id="legArrow" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto">
                                                <polygon points="0 0, 10 4, 0 8" fill="rgba(72,140,154,0.8)"/>
                                            </marker>
                                        </defs>
                                        <!-- Leg lines rendered by JS -->
                                    </svg>

                                    <!-- Drag Preview Line -->
                                    <svg class="journey-drag-preview" id="journeyDragPreview" width="100%" height="100%" style="overflow: visible;">
                                        <line id="journeyDragLine" stroke="rgba(72,140,154,0.5)" stroke-width="3" stroke-dasharray="8 4"/>
                                    </svg>
                                </div>

                                <!-- Empty State (shown when no modules allocated) -->
                                <div id="journeyEmptyState" class="journey-empty-state" style="display: none;">
                                    <div class="journey-empty-icon">
                                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <rect x="1" y="3" width="15" height="13"/>
                                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                                            <circle cx="5.5" cy="18.5" r="2.5"/>
                                            <circle cx="18.5" cy="18.5" r="2.5"/>
                                        </svg>
                                    </div>
                                    <h4>Add Modules First</h4>
                                    <p>Add module batches in the Modules section to plan your delivery route.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Map View -->
                        <div class="logistics-view" id="logistics-map-view">
                            <div class="map-wrapper">
                                <div class="route-map-container">
                                    <div id="routeMap"></div>
                                    <div class="map-stats-overlay" id="mapStatsOverlay"></div>
                                    <button class="map-fullscreen-close" onclick="toggleMapFullscreen()" title="Exit Fullscreen">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                        </svg>
                                    </button>
                                    <div class="map-placeholder" id="mapPlaceholder">
                                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/>
                                            <line x1="8" y1="2" x2="8" y2="18"/>
                                            <line x1="16" y1="6" x2="16" y2="22"/>
                                        </svg>
                                        <p>Add delivery steps to see the route</p>
                                    </div>
                                </div>
                                <div class="map-legend">
                                    <div class="legend-section">
                                        <div class="legend-item">
                                            <span class="legend-dot origin"></span>
                                            <span>Origin</span>
                                        </div>
                                        <div class="legend-item">
                                            <span class="legend-dot warehouse"></span>
                                            <span>Warehouse/Port</span>
                                        </div>
                                        <div class="legend-item">
                                            <span class="legend-dot destination"></span>
                                            <span>Destination</span>
                                        </div>
                                        <div class="legend-item">
                                            <span class="legend-line"></span>
                                            <span>Route</span>
                                        </div>
                                    </div>
                                    <div class="legend-hint">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        <span>Line thickness indicates volume</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Collapsible: Timeline & Costs -->
        <div class="collapsible-section timeline-section" data-section="timeline">
            <div class="collapsible-header" onclick="toggleSection('timeline')">
                <div class="collapsible-title">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Projected Timeline & Costs
                </div>
                <div class="collapsible-meta">
                    <!-- Simplified timeline summary -->
                    <div class="collapsed-summary" id="timelineCollapsedSummary">
                        <span class="summary-text" id="timelineDateText">No dates yet</span>
                        <span class="summary-divider">|</span>
                        <span class="summary-highlight total" id="timelineGrandTotal">$0 projected total</span>
                    </div>
                    <div class="collapsible-toggle">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="collapsible-content" id="timeline-content">
                <!-- Chart Type Tabs -->
                <div class="timeline-tabs">
                    <button type="button" class="timeline-tab active" data-tab="cost-summary" onclick="switchTimelineTab('cost-summary')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle;">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        Cost Summary
                    </button>
                    <button type="button" class="timeline-tab" data-tab="line-chart" onclick="switchTimelineTab('line-chart')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle;">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        Monthly Forecast
                    </button>
                </div>

                <!-- Cost Summary Panel -->
                <div class="timeline-panel active" id="cost-summary-panel">
                    <?php include 'components/projection_cost_summary.php'; ?>
                </div>

                <!-- Line Chart Panel -->
                <div class="timeline-panel" id="line-chart-panel">
                    <div class="monthly-forecast-container">
                        <div class="monthly-chart-wrapper">
                            <canvas id="monthlyForecastChart"></canvas>
                        </div>
                        <div class="monthly-chart-legend">
                            <div class="legend-item-chart">
                                <span class="legend-color freight"></span>
                                <span>Freight Costs</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color warehousing"></span>
                                <span>Warehousing Costs</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color milestone"></span>
                                <span>Milestone Payments</span>
                            </div>
                            <div class="legend-item-chart">
                                <span class="legend-color total"></span>
                                <span>Cumulative Total</span>
                            </div>
                        </div>

                        <!-- Weekly Cost Projections Table -->
                        <div class="weekly-projections-section" id="weeklyProjectionsSection">
                            <div class="weekly-projections-header" onclick="toggleWeeklyProjections()">
                                <h4 class="weekly-projections-title">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    Weekly Cost Projections
                                </h4>
                                <div class="weekly-projections-toggle">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="weekly-projections-content" id="weeklyProjectionsContent">
                                <div class="weekly-table-wrapper">
                                    <table class="weekly-projections-table" id="weeklyProjectionsTable">
                                        <thead>
                                            <tr>
                                                <th>Week</th>
                                                <th>Date Range</th>
                                                <th>Freight</th>
                                                <th>Warehousing</th>
                                                <th>Milestones</th>
                                                <th>Weekly Total</th>
                                                <th>Cumulative</th>
                                            </tr>
                                        </thead>
                                        <tbody id="weeklyProjectionsBody">
                                            <!-- Populated by JavaScript -->
                                        </tbody>
                                    </table>
                                </div>
                                <div class="weekly-note">
                                    PO execution milestones are applied to the week of each PO execution date (or the current week if no date is set).
                                </div>
                                <div class="weekly-empty-state" id="weeklyEmptyState" style="display: none;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="16" y1="2" x2="16" y2="6"/>
                                        <line x1="8" y1="2" x2="8" y2="6"/>
                                        <line x1="3" y1="10" x2="21" y2="10"/>
                                    </svg>
                                    <p>Add dates to your logistics plan to see weekly cost projections.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="timeline-cumulative">
                    <div class="cumulative-item">
                        <div class="cumulative-label">Total Freight</div>
                        <div class="cumulative-value" id="totalFreightDisplay">$0</div>
                    </div>
                    <div class="cumulative-item">
                        <div class="cumulative-label">Total Warehousing</div>
                        <div class="cumulative-value" id="totalWarehousingDisplay">$0</div>
                    </div>
                    <div class="cumulative-item">
                        <div class="cumulative-label">Total Milestones</div>
                        <div class="cumulative-value" id="totalMilestonesDisplay">$0</div>
                    </div>
                    <div class="cumulative-item">
                        <div class="cumulative-label">Grand Total</div>
                        <div class="cumulative-value" id="grandTotalDisplay" style="color: #E07F3A; font-size: 1.3em;">$0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actual vs Projected Comparison -->
        <?php if ($current_projection && isset($current_projection['actual_deliveries']) && count($current_projection['actual_deliveries']) > 0): ?>
        <div class="comparison-card">
            <div class="comparison-header">
                <h3 class="comparison-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6" y1="20" x2="6" y2="14"/>
                    </svg>
                    Actual vs Projected
                </h3>
            </div>
            <div class="comparison-body">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Projected</th>
                            <th>Actual</th>
                            <th>Variance</th>
                        </tr>
                    </thead>
                    <tbody id="comparisonTableBody">
                        <!-- Generated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <div class="progress-indicator">
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" id="deliveryProgressBar" style="width: 0%;"></div>
                </div>
                <div class="progress-text" id="deliveryProgressText">0 of 0 deliveries completed</div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; // End of projection exists else block ?>

    </main>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>

    <!-- ==================== FLOW JOURNEY MODALS ==================== -->

    <!-- Node Editor Modal -->
    <div class="flow-modal-overlay" id="nodeEditorModal">
        <div class="flow-modal">
            <div class="flow-modal-header">
                <div class="flow-modal-header-icon" id="nodeModalIcon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                </div>
                <div class="flow-modal-header-text">
                    <h3 id="nodeModalTitle">Edit Stop</h3>
                    <p id="nodeModalSubtitle">Configure this location</p>
                </div>
                <button type="button" class="flow-modal-close" onclick="closeNodeEditor()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="flow-modal-body" id="nodeModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="flow-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeNodeEditor()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveNodeEditor()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Leg Editor Modal -->
    <div class="flow-modal-overlay" id="legEditorModal">
        <div class="flow-modal flow-modal-wide">
            <div class="flow-modal-header">
                <div class="flow-modal-header-icon leg-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="3" width="15" height="13"/>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
                        <circle cx="5.5" cy="18.5" r="2.5"/>
                        <circle cx="18.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <div class="flow-modal-header-text">
                    <h3 id="legModalTitle">Configure Transport</h3>
                    <p id="legModalSubtitle">Set transport details for this leg</p>
                </div>
                <button type="button" class="flow-modal-close" onclick="closeLegEditor()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="flow-modal-body" id="legModalBody">
                <!-- Dynamic content -->
            </div>
            <div class="flow-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLegEditor()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveLegEditor()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Save Transport
                </button>
            </div>
        </div>
    </div>

    <!-- Add Stop Modal -->
    <div class="flow-modal-overlay" id="addStopModal">
        <div class="flow-modal flow-modal-compact">
            <div class="flow-modal-header">
                <div class="flow-modal-header-icon add-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="16"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                </div>
                <div class="flow-modal-header-text">
                    <h3>Add to Journey</h3>
                    <p id="addStopSubtitle">Choose what to add after this stop</p>
                </div>
                <button type="button" class="flow-modal-close" onclick="closeAddStopModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="flow-modal-body">
                <div class="add-stop-options">
                    <button type="button" class="add-stop-option" onclick="addSingleStop()">
                        <div class="add-stop-option-icon warehouse">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                <polyline points="9 22 9 12 15 12 15 22"/>
                            </svg>
                        </div>
                        <div class="add-stop-option-text">
                            <strong>Add Single Stop</strong>
                            <span>Warehouse, port, or storage facility</span>
                        </div>
                        <svg class="add-stop-option-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </button>
                    <button type="button" class="add-stop-option" onclick="addBranchSplit()">
                        <div class="add-stop-option-icon branch">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="5" r="3"/>
                                <circle cx="6" cy="19" r="3"/>
                                <circle cx="18" cy="19" r="3"/>
                                <path d="M12 8v4"/>
                                <path d="M12 12l-6 4"/>
                                <path d="M12 12l6 4"/>
                            </svg>
                        </div>
                        <div class="add-stop-option-text">
                            <strong>Split Into Branches</strong>
                            <span>Send cargo to multiple destinations</span>
                        </div>
                        <svg class="add-stop-option-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Branch Split Configuration Modal -->
    <div class="flow-modal-overlay" id="branchConfigModal">
        <div class="flow-modal">
            <div class="flow-modal-header">
                <div class="flow-modal-header-icon branch-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="5" r="3"/>
                        <circle cx="6" cy="19" r="3"/>
                        <circle cx="18" cy="19" r="3"/>
                        <path d="M12 8v4"/>
                        <path d="M12 12l-6 4"/>
                        <path d="M12 12l6 4"/>
                    </svg>
                </div>
                <div class="flow-modal-header-text">
                    <h3>Configure Branch Split</h3>
                    <p>Divide your shipment between locations</p>
                </div>
                <button type="button" class="flow-modal-close" onclick="closeBranchConfig()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="flow-modal-body" id="branchConfigBody">
                <div class="branch-split-info">
                    <div class="branch-total-trucks">
                        <span class="label">Total Trucks Available</span>
                        <span class="value" id="branchTotalTrucks">0</span>
                    </div>
                </div>
                <div class="branch-config-list" id="branchConfigList">
                    <!-- Branch items populated by JS -->
                </div>
                <button type="button" class="add-branch-btn" id="addBranchBtn" onclick="addAnotherBranch()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Another Branch
                </button>
                <div class="branch-allocation-bar">
                    <div class="branch-allocation-fill" id="branchAllocationFill"></div>
                </div>
                <div class="branch-allocation-status" id="branchAllocationStatus">
                    <span class="allocated">0 trucks allocated</span>
                    <span class="remaining">0 remaining</span>
                </div>
            </div>
            <div class="flow-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeBranchConfig()">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBranchBtn" onclick="saveBranchConfig()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Create Branches
                </button>
            </div>
        </div>
    </div>

    <!-- Merge Branches Modal -->
    <div class="flow-modal-overlay" id="mergeModal">
        <div class="flow-modal flow-modal-compact">
            <div class="flow-modal-header">
                <div class="flow-modal-header-icon merge-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="6" cy="5" r="3"/>
                        <circle cx="18" cy="5" r="3"/>
                        <circle cx="12" cy="19" r="3"/>
                        <path d="M6 8v4l6 4"/>
                        <path d="M18 8v4l-6 4"/>
                    </svg>
                </div>
                <div class="flow-modal-header-text">
                    <h3>Merge Branches</h3>
                    <p>Combine shipments back together</p>
                </div>
                <button type="button" class="flow-modal-close" onclick="closeMergeModal()">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="flow-modal-body" id="mergeModalBody">
                <div class="merge-branches-list" id="mergeBranchesList">
                    <!-- Branches to merge -->
                </div>
            </div>
            <div class="flow-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMergeModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmMerge()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Merge to Destination
                </button>
            </div>
        </div>
    </div>

    <?php include 'components/anticipated_deliveries_scripts.php'; ?>
</body>
</html>
