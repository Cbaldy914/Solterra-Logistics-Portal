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

if (!in_array($role, ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: dashboard");
    exit();
}

require_once '../config.php';
require_once 'projection_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
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
        $row['has_projection'] = project_has_projection($conn, $row['id']);
        $projects[] = $row;
    }
    $stmt->close();
}

// Get general projections
$general_projections = get_general_projections($conn, $user_id, $role);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Planning - Solterra</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php
    $google_maps_key = getenv('GOOGLE_MAPS_API_KEY') ?: '';
    if (!empty($google_maps_key)):
    ?>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?php echo $google_maps_key; ?>&libraries=places"></script>
    <?php endif; ?>
    <style>
        /* Header Section - Matches global page pattern */
        .planning-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .planning-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .planning-header .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .planning-header .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .planning-header .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .btn-create-general {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .btn-create-general:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        .section-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title .count-badge {
            background: #e8f4f6;
            color: #488C9A;
            font-size: 0.7em;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .projects-section {
            margin-bottom: 40px;
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        .project-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(72, 140, 154, 0.15);
            border-color: #488C9A;
        }

        .project-card-image {
            width: 100%;
            height: 140px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            position: relative;
            overflow: hidden;
        }

        .project-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .project-card-image .placeholder-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 3em;
            color: rgba(255, 255, 255, 0.3);
        }

        .schedule-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .schedule-badge.has-schedule {
            background: rgba(72, 140, 154, 0.95);
            color: #fff;
        }

        .schedule-badge.no-schedule {
            background: rgba(251, 176, 64, 0.95);
            color: #293E4C;
        }

        .general-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.95);
            color: #488C9A;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .project-card-content {
            padding: 20px;
        }

        .project-card-title {
            font-size: 1.1em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 4px 0;
        }

        .project-card-location {
            font-size: 0.85em;
            color: #6c757d;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .project-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid #f1f3f4;
        }

        .project-card-meta .account {
            font-size: 0.8em;
            color: #6c757d;
        }

        .project-card-meta .action {
            font-size: 0.85em;
            color: #488C9A;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .general-card-stats {
            display: flex;
            gap: 16px;
            margin-bottom: 12px;
        }

        .general-card-stat {
            font-size: 0.8em;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .general-card-stat strong {
            color: #293E4C;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: #fff;
            border-radius: 16px;
            border: 2px dashed #d0d0d0;
        }

        .empty-state i {
            font-size: 3em;
            color: #d0d0d0;
            margin-bottom: 16px;
        }

        .empty-state h3 {
            color: #6c757d;
            margin: 0 0 8px 0;
        }

        .empty-state p {
            color: #adb5bd;
            margin: 0;
        }

        /* Modal styles */
        .gp-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .gp-modal-overlay.active {
            display: flex;
        }

        .gp-modal {
            background: #fff;
            border-radius: 20px;
            width: 90%;
            max-width: 520px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .gp-modal-header {
            padding: 24px 28px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
        }

        .gp-modal-header h3 {
            margin: 0;
            font-size: 1.2em;
        }

        .gp-modal-header p {
            margin: 4px 0 0;
            font-size: 0.85em;
            opacity: 0.85;
        }

        .gp-modal-body {
            padding: 28px;
        }

        .gp-form-group {
            margin-bottom: 20px;
        }

        .gp-form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.9em;
            color: #293E4C;
            margin-bottom: 8px;
        }

        .gp-form-group label .required {
            color: #dc3545;
        }

        .gp-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95em;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .gp-form-input:focus {
            outline: none;
            border-color: #488C9A;
        }

        .gp-modal-footer {
            padding: 16px 28px;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e9ecef;
        }

        .gp-btn {
            padding: 10px 24px;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .gp-btn-secondary {
            background: #e9ecef;
            color: #495057;
        }

        .gp-btn-secondary:hover {
            background: #dee2e6;
        }

        .gp-btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .gp-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.4);
        }

        .gp-btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Ensure Google Places dropdown appears above modal */
        .pac-container {
            z-index: 99999 !important;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-header-left h1 {
                font-size: 1.5em;
            }

            .projects-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Project Planning']); ?>

    <div class="planning-header">
        <div class="header-content">
            <div class="header-info">
                <h1>Project Planning</h1>
                <p class="header-subtitle">Plan delivery schedules and forecast costs for your projects</p>
            </div>
            <button type="button" class="btn-create-general" onclick="openCreateGeneralModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create General Projection
            </button>
        </div>
    </div>

    <?php if (!empty($general_projections)): ?>
    <div class="projects-section">
        <h2 class="section-title">
            <i class="fas fa-clipboard-list" style="color: #488C9A;"></i>
            General Projections
            <span class="count-badge"><?php echo count($general_projections); ?></span>
        </h2>
        <div class="projects-grid">
            <?php foreach ($general_projections as $gp): ?>
            <a href="anticipated_deliveries.php?projection_id=<?php echo $gp['id']; ?>&is_general=1" class="project-card">
                <div class="project-card-image" style="height: 100px; background: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%);">
                    <i class="fas fa-clipboard-list placeholder-icon"></i>
                    <span class="general-badge">
                        <i class="fas fa-star"></i> General
                    </span>
                    <?php if (!empty($gp['linked_project_id'])): ?>
                    <span class="schedule-badge has-schedule">
                        <i class="fas fa-link"></i> Linked
                    </span>
                    <?php endif; ?>
                </div>
                <div class="project-card-content">
                    <h3 class="project-card-title"><?php echo htmlspecialchars($gp['general_project_name'] ?? $gp['projection_name']); ?></h3>
                    <p class="project-card-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($gp['general_project_address'] ?? 'No address set'); ?>
                    </p>
                    <div class="general-card-stats">
                        <?php if (!empty($gp['general_estimated_mw'])): ?>
                        <span class="general-card-stat">
                            <i class="fas fa-bolt"></i>
                            <strong><?php echo number_format($gp['general_estimated_mw'], 1); ?></strong> MW
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($gp['grand_total'])): ?>
                        <span class="general-card-stat">
                            <i class="fas fa-dollar-sign"></i>
                            <strong>$<?php echo number_format($gp['grand_total'], 0); ?></strong>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="project-card-meta">
                        <span class="account"><?php echo htmlspecialchars($gp['created_by_name'] ?? 'Unknown'); ?></span>
                        <span class="action">
                            View Projection
                            <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="projects-section">
        <h2 class="section-title">
            <i class="fas fa-solar-panel" style="color: #488C9A;"></i>
            Projects
            <span class="count-badge"><?php echo count($projects); ?></span>
        </h2>

        <?php if (empty($projects)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>No Projects Found</h3>
            <p>Create a project first to start planning deliveries, or create a general projection above.</p>
        </div>
        <?php else: ?>
        <div class="projects-grid">
            <?php foreach ($projects as $project): ?>
            <a href="anticipated_deliveries.php?project_id=<?php echo $project['id']; ?>" class="project-card">
                <div class="project-card-image">
                    <?php if (!empty($project['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>">
                    <?php else: ?>
                        <i class="fas fa-solar-panel placeholder-icon"></i>
                    <?php endif; ?>

                    <?php $has_schedule = $project['has_schedule'] ?? ($project['has_projection'] ?? false); ?>
                    <?php if ($has_schedule): ?>
                        <span class="schedule-badge has-schedule">
                            <i class="fas fa-check-circle"></i> Plan Set
                        </span>
                    <?php else: ?>
                        <span class="schedule-badge no-schedule">
                            <i class="fas fa-plus-circle"></i> Add Plan
                        </span>
                    <?php endif; ?>
                </div>
                <div class="project-card-content">
                    <h3 class="project-card-title"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                    <p class="project-card-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($project['project_address'] ?? 'No address set'); ?>
                    </p>
                    <div class="project-card-meta">
                        <span class="account"><?php echo htmlspecialchars($project['account_name']); ?></span>
                        <span class="action">
                            <?php echo $has_schedule ? 'View Plan' : 'Create Plan'; ?>
                            <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- Create General Projection Modal -->
<div class="gp-modal-overlay" id="createGeneralModal">
    <div class="gp-modal">
        <div class="gp-modal-header">
            <h3>Create General Projection</h3>
            <p>Plan a delivery before the project is in the system</p>
        </div>
        <div class="gp-modal-body">
            <div class="gp-form-group">
                <label>Project Name <span class="required">*</span></label>
                <input type="text" class="gp-form-input" id="gpProjectName" placeholder="e.g., Solar Farm Phase 2" required>
            </div>
            <div class="gp-form-group">
                <label>Project Address <span class="required">*</span></label>
                <input type="text" class="gp-form-input" id="gpProjectAddress" placeholder="Start typing an address...">
            </div>
            <div class="gp-form-group">
                <label>Estimated MW <span class="required">*</span></label>
                <input type="number" class="gp-form-input" id="gpEstimatedMW" placeholder="e.g., 50" step="0.1" min="0">
            </div>
        </div>
        <div class="gp-modal-footer">
            <button type="button" class="gp-btn gp-btn-secondary" onclick="closeCreateGeneralModal()">Cancel</button>
            <button type="button" class="gp-btn gp-btn-primary" id="gpSubmitBtn" onclick="submitCreateGeneral()">
                Create Projection
            </button>
        </div>
    </div>
</div>

<script>
let gpAutocomplete = null;

function initGpAddressAutocomplete() {
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;
    if (gpAutocomplete) return; // already initialized

    const input = document.getElementById('gpProjectAddress');
    gpAutocomplete = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        fields: ['formatted_address', 'geometry', 'name']
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') e.preventDefault();
    });

    gpAutocomplete.addListener('place_changed', function() {
        const place = gpAutocomplete.getPlace();
        if (place && place.formatted_address) {
            input.value = place.formatted_address;
        }
    });
}

function openCreateGeneralModal() {
    document.getElementById('createGeneralModal').classList.add('active');
    document.getElementById('gpProjectName').value = '';
    document.getElementById('gpProjectAddress').value = '';
    document.getElementById('gpEstimatedMW').value = '';
    setTimeout(() => {
        document.getElementById('gpProjectName').focus();
        initGpAddressAutocomplete();
    }, 100);
}

function closeCreateGeneralModal() {
    document.getElementById('createGeneralModal').classList.remove('active');
}

function submitCreateGeneral() {
    const name = document.getElementById('gpProjectName').value.trim();
    const address = document.getElementById('gpProjectAddress').value.trim();
    const mw = document.getElementById('gpEstimatedMW').value.trim();

    if (!name) {
        alert('Project name is required.');
        document.getElementById('gpProjectName').focus();
        return;
    }
    if (!address) {
        alert('Project address is required.');
        document.getElementById('gpProjectAddress').focus();
        return;
    }
    if (!mw || parseFloat(mw) <= 0) {
        alert('Estimated MW is required and must be greater than 0.');
        document.getElementById('gpEstimatedMW').focus();
        return;
    }

    const btn = document.getElementById('gpSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Creating...';

    fetch('api/projection_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            is_general: true,
            project_id: 0,
            projection_name: name,
            general_project_name: name,
            general_project_address: address,
            general_estimated_mw: parseFloat(mw)
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = `anticipated_deliveries.php?projection_id=${data.projection_id}&is_general=1`;
        } else {
            alert('Failed to create projection: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.textContent = 'Create Projection';
        }
    })
    .catch(err => {
        alert('Error creating projection.');
        console.error(err);
        btn.disabled = false;
        btn.textContent = 'Create Projection';
    });
}

// Close modal on overlay click
document.getElementById('createGeneralModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateGeneralModal();
});
</script>

</body>
</html>
