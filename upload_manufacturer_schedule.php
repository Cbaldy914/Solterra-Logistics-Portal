<?php
/**
 * Upload Manufacturer Schedule - Hub Page
 *
 * This page redirects users to the appropriate specialized upload process:
 * - Import Pallets: For adding modules/pallets to inventory
 * - Import Shipments: For creating deliveries and linking existing pallets
 */

session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Allow admin, global_admin, and customer_admin
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

// Get project_id from URL if provided
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : null;
$project_param = $project_id ? "?project_id=$project_id" : "";

// Get project name if project_id is provided
$project_name = null;
if ($project_id) {
    require_once '../config.php';
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $project_name = $row['project_name'];
        }
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Data<?php echo $project_name ? ' - ' . htmlspecialchars($project_name) : ''; ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .container { padding: 20px; max-width: 900px; margin: 0 auto; }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
        }
        .page-header p {
            color: #6c757d;
            margin: 0;
            font-size: 1.05rem;
        }

        /* Import Options */
        .import-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 24px;
        }

        .import-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06);
            padding: 32px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .import-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(72, 140, 154, 0.15);
            border-color: #488C9A;
        }

        .import-card-icon {
            width: 72px;
            height: 72px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin-bottom: 20px;
        }
        .import-card-icon.pallets {
            background: linear-gradient(135deg, #e8f4f8 0%, #d4edda 100%);
        }
        .import-card-icon.shipments {
            background: linear-gradient(135deg, #e7f3ff 0%, #f0e6ff 100%);
        }

        .import-card h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 12px 0;
        }

        .import-card p {
            color: #6c757d;
            margin: 0 0 20px 0;
            line-height: 1.6;
        }

        .import-card-features {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }
        .import-card-features li {
            padding: 6px 0;
            color: #495057;
            font-size: 0.95rem;
        }
        .import-card-features li::before {
            content: "✓ ";
            color: #28a745;
            font-weight: bold;
        }

        .import-card-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #488C9A;
            font-weight: 600;
            font-size: 1rem;
        }
        .import-card:hover .import-card-cta {
            color: #3a7086;
        }

        /* Info Banner */
        .info-banner {
            background: linear-gradient(135deg, #fff3cd 0%, #fffbe6 100%);
            border: 1px solid #ffc107;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 32px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .info-banner-icon {
            font-size: 24px;
            flex-shrink: 0;
        }
        .info-banner-content h3 {
            color: #856404;
            margin: 0 0 8px 0;
            font-size: 1rem;
        }
        .info-banner-content p {
            color: #856404;
            margin: 0;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Back link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #6c757d;
            text-decoration: none;
            margin-bottom: 24px;
            font-weight: 500;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: #488C9A;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="container">
        <!-- Breadcrumb -->
        <?php
            require_once 'components/breadcrumbs.php';
            $breadcrumb_extra = [];
            if ($project_name) {
                $breadcrumb_extra[] = ['label' => htmlspecialchars($project_name), 'url' => 'project_overview.php?project_id=' . $project_id];
            }
            echo slp_render_breadcrumbs([
                'current_label' => 'Import Data',
                'extra' => $breadcrumb_extra
            ]);
        ?>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Import Data</h1>
            <p>Choose the type of data you want to import from your manufacturer's schedule.</p>
        </div>

        <!-- Info Banner -->
        <div class="info-banner">
            <div class="info-banner-icon">💡</div>
            <div class="info-banner-content">
                <h3>Recommended Import Order</h3>
                <p>For best results, import your pallets first to add them to inventory, then import shipments to create deliveries and link to those pallets. This ensures all pallets are available when creating shipments.</p>
            </div>
        </div>

        <!-- Import Options -->
        <div class="import-options">
            <!-- Import Pallets Card -->
            <a href="upload_pallets.php<?php echo $project_param; ?>" class="import-card">
                <div class="import-card-icon pallets">📦</div>
                <h2>Import Pallets</h2>
                <p>Add modules and pallets to your inventory from a manufacturer's packing list or schedule.</p>
                <ul class="import-card-features">
                    <li>Creates module batches by wattage</li>
                    <li>Generates inventory pallets with unique IDs</li>
                    <li>Tracks container numbers</li>
                    <li>Supports serial number ranges</li>
                </ul>
                <div class="import-card-cta">
                    Start Pallet Import <span>→</span>
                </div>
            </a>

            <!-- Import Shipments Card -->
            <a href="upload_shipments.php<?php echo $project_param; ?>" class="import-card">
                <div class="import-card-icon shipments">📥</div>
                <h2>Import Shipments</h2>
                <p>Create deliveries and link existing pallets from a shipping manifest or BOL list.</p>
                <ul class="import-card-features">
                    <li>Creates delivery records with BOL numbers</li>
                    <li>Links existing pallets to deliveries</li>
                    <li>Sets delivery dates and statuses</li>
                    <li>Groups pallets by shipment</li>
                </ul>
                <div class="import-card-cta">
                    Start Shipment Import <span>→</span>
                </div>
            </a>
        </div>
    </div>
</main>
</body>
</html>
