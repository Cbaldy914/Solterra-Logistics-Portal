<?php
session_name("logistics_session");
session_start();

// Allow access for all roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'])) {
    header('Location: unauthorized');
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get success message if redirected from close-out
$success_message = $_SESSION['archive_success'] ?? '';
unset($_SESSION['archive_success']);

// Handle download request
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $archive_id = (int)$_GET['download'];

    // Verify access to this archive
    if ($user_role === 'global_admin') {
        $stmt = $conn->prepare('SELECT archive_path, archive_filename FROM archived_projects WHERE id = ?');
        $stmt->bind_param('i', $archive_id);
    } else {
        $stmt = $conn->prepare('
            SELECT ap.archive_path, ap.archive_filename
            FROM archived_projects ap
            JOIN customer_account_users cau ON ap.account_id = cau.account_id
            WHERE ap.id = ? AND cau.user_id = ?
        ');
        $stmt->bind_param('ii', $archive_id, $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $archive = $result->fetch_assoc();
    $stmt->close();

    if ($archive) {
        $filePath = __DIR__ . '/' . $archive['archive_path'];
        if (file_exists($filePath)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $archive['archive_filename'] . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit();
        }
    }
    $_SESSION['archive_error'] = 'Archive file not found or access denied.';
    header('Location: archived_projects.php');
    exit();
}

$error_message = $_SESSION['archive_error'] ?? '';
unset($_SESSION['archive_error']);

// Fetch archived projects based on user role
if ($user_role === 'global_admin') {
    $sql = '
        SELECT ap.*, u.username as closed_by_name, ca.name as account_name
        FROM archived_projects ap
        LEFT JOIN users u ON ap.closed_by = u.id
        LEFT JOIN customer_accounts ca ON ap.account_id = ca.id
        ORDER BY ap.closed_at DESC
    ';
    $result = $conn->query($sql);
} else {
    $sql = '
        SELECT ap.*, u.username as closed_by_name, ca.name as account_name
        FROM archived_projects ap
        JOIN customer_account_users cau ON ap.account_id = cau.account_id
        LEFT JOIN users u ON ap.closed_by = u.id
        LEFT JOIN customer_accounts ca ON ap.account_id = ca.id
        WHERE cau.user_id = ?
        ORDER BY ap.closed_at DESC
    ';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

$archives = [];
while ($row = $result->fetch_assoc()) {
    $archives[] = $row;
}

if (isset($stmt)) {
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .archive-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .archive-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .archive-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .archive-header p {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        @media (max-width: 768px) {
            .archive-header { padding: 24px; border-radius: 16px; }
            .archive-header h1 { font-size: 1.8em; }
            .archive-header p { font-size: 1em; }
        }
        .archive-content {
            padding: 0;
        }
        .archive-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 16px;
        }
        .archive-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            padding: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .archive-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .archive-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .archive-card h3 {
            margin: 0;
            font-size: 1.1em;
            color: #293E4C;
            font-weight: 600;
        }
        .archive-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-weight: 600;
            background: #e8f4f7;
            color: #488C9A;
        }
        .archive-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 16px;
        }
        .archive-meta-item {
            font-size: 0.85em;
        }
        .archive-meta-item .label {
            color: #6c757d;
            display: block;
        }
        .archive-meta-item .value {
            color: #293E4C;
            font-weight: 500;
        }
        .archive-progress {
            margin-bottom: 16px;
        }
        .archive-progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 999px;
            overflow: hidden;
        }
        .archive-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4db6ac, #2c98a0);
            border-radius: 999px;
        }
        .archive-progress-text {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 4px;
        }
        .archive-actions {
            display: flex;
            gap: 10px;
        }
        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-weight: 600;
            font-size: 0.9em;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
        }
        .btn-download:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72,140,154,0.3);
        }
        .file-size {
            font-size: 0.8em;
            color: #6c757d;
            margin-left: auto;
            align-self: center;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 14px 20px;
            border-radius: 10px;
            margin: 0 0 20px;
            border: 1px solid #c3e6cb;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 14px 20px;
            border-radius: 10px;
            margin: 0 0 20px;
            border: 1px solid #f5c6cb;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state h2 {
            color: #293E4C;
            margin-bottom: 10px;
        }

        /* View Toggle */
        .section-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .section-header {
            color: #293E4C;
            font-size: 1.2em;
            font-weight: 600;
            margin: 0;
        }
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
        .view-toggle button:hover {
            color: #293E4C;
            background: rgba(255,255,255,0.5);
        }
        .view-toggle button.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        /* Grid/Table Toggle */
        .archive-grid { display: none; }
        .archive-grid.active { display: grid; }

        /* Table View */
        .archive-table-container {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            border: 1px solid #e9ecef;
            overflow: hidden;
            display: none;
        }
        .archive-table-container.active { display: block; }
        .archive-table {
            width: 100%;
            border-collapse: collapse;
        }
        .archive-table th {
            background: #f8f9fa;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.8em;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }
        .archive-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f3f4;
            color: #495057;
            font-size: 0.9em;
            vertical-align: middle;
        }
        .archive-table tbody tr {
            transition: background 0.2s;
        }
        .archive-table tbody tr:hover {
            background: #f8f9fa;
        }
        .archive-table .project-name {
            font-weight: 600;
            color: #293E4C;
        }
        .archive-table .progress-cell {
            min-width: 150px;
        }
        .archive-table .progress-bar-mini {
            height: 6px;
            background: #e9ecef;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 4px;
        }
        .archive-table .progress-fill-mini {
            height: 100%;
            background: linear-gradient(90deg, #4db6ac, #2c98a0);
            border-radius: 999px;
        }
        .archive-table .progress-text-mini {
            font-size: 0.8em;
            color: #6c757d;
        }
        .archive-table .btn-download-sm {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-weight: 600;
            font-size: 0.8em;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
            white-space: nowrap;
        }
        .archive-table .btn-download-sm:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72,140,154,0.3);
        }
        .archive-table .file-size-sm {
            font-size: 0.75em;
            color: #6c757d;
            margin-left: 8px;
        }

        @media (max-width: 768px) {
            .archive-table-container { overflow-x: auto; }
            .archive-table { min-width: 800px; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs(['current_label' => 'Archived Projects']);
    ?>

    <div class="archive-header">
        <h1>Archived Projects</h1>
        <p>View and download data packages from closed projects.</p>
    </div>

    <?php if ($success_message): ?>
    <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="archive-content">
        <?php if (empty($archives)): ?>
        <div class="empty-state">
            <h2>No Archived Projects</h2>
            <p>When projects are closed out, their archives will appear here.</p>
        </div>
        <?php else: ?>
        <div class="section-header-row">
            <h2 class="section-header"><?php echo count($archives); ?> Archived Project<?php echo count($archives) !== 1 ? 's' : ''; ?></h2>
            <div class="view-toggle">
                <button class="active" onclick="setView('grid')" id="btn-grid" title="Grid View">▦</button>
                <button onclick="setView('table')" id="btn-table" title="Table View">☰</button>
            </div>
        </div>

        <div class="archive-grid active" id="archive-grid">
            <?php foreach ($archives as $archive): ?>
            <div class="archive-card">
                <div class="archive-card-header">
                    <h3><?php echo htmlspecialchars($archive['project_name']); ?></h3>
                    <span class="archive-badge">Archived</span>
                </div>

                <div class="archive-meta">
                    <div class="archive-meta-item">
                        <span class="label">Account</span>
                        <span class="value"><?php echo htmlspecialchars($archive['account_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="archive-meta-item">
                        <span class="label">Project ID</span>
                        <span class="value">#<?php echo (int)$archive['project_id']; ?></span>
                    </div>
                    <div class="archive-meta-item">
                        <span class="label">Closed By</span>
                        <span class="value"><?php echo htmlspecialchars($archive['closed_by_name'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="archive-meta-item">
                        <span class="label">Closed On</span>
                        <span class="value"><?php echo date('M j, Y', strtotime($archive['closed_at'])); ?></span>
                    </div>
                </div>

                <div class="archive-progress">
                    <div class="archive-progress-bar">
                        <div class="archive-progress-fill" style="width: <?php echo min(100, $archive['delivery_percent']); ?>%;"></div>
                    </div>
                    <div class="archive-progress-text">
                        <?php echo number_format($archive['delivery_percent'], 1); ?>% delivered
                        (<?php echo number_format($archive['delivered_modules']); ?> / <?php echo number_format($archive['total_modules']); ?> modules)
                    </div>
                </div>

                <div class="archive-actions">
                    <a href="?download=<?php echo (int)$archive['id']; ?>" class="btn-download">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8 12l-4-4h2.5V3h3v5H12L8 12z"/>
                            <path d="M14 14H2v-2h12v2z"/>
                        </svg>
                        Download Archive
                    </a>
                    <span class="file-size">
                        <?php
                        $size = $archive['file_size_bytes'] ?? 0;
                        if ($size >= 1073741824) {
                            echo number_format($size / 1073741824, 2) . ' GB';
                        } elseif ($size >= 1048576) {
                            echo number_format($size / 1048576, 1) . ' MB';
                        } elseif ($size >= 1024) {
                            echo number_format($size / 1024, 0) . ' KB';
                        } else {
                            echo $size . ' bytes';
                        }
                        ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="archive-table-container" id="archive-table">
            <table class="archive-table">
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Account</th>
                        <th>Closed By</th>
                        <th>Closed On</th>
                        <th>Delivery Progress</th>
                        <th>Download</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($archives as $archive): ?>
                    <tr>
                        <td class="project-name"><?php echo htmlspecialchars($archive['project_name']); ?></td>
                        <td><?php echo htmlspecialchars($archive['account_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($archive['closed_by_name'] ?? 'Unknown'); ?></td>
                        <td><?php echo date('M j, Y', strtotime($archive['closed_at'])); ?></td>
                        <td class="progress-cell">
                            <div class="progress-bar-mini">
                                <div class="progress-fill-mini" style="width: <?php echo min(100, $archive['delivery_percent']); ?>%;"></div>
                            </div>
                            <div class="progress-text-mini">
                                <?php echo number_format($archive['delivery_percent'], 1); ?>%
                                (<?php echo number_format($archive['delivered_modules']); ?>/<?php echo number_format($archive['total_modules']); ?>)
                            </div>
                        </td>
                        <td>
                            <a href="?download=<?php echo (int)$archive['id']; ?>" class="btn-download-sm">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M8 12l-4-4h2.5V3h3v5H12L8 12z"/>
                                    <path d="M14 14H2v-2h12v2z"/>
                                </svg>
                                Download
                            </a>
                            <span class="file-size-sm">
                                <?php
                                $size = $archive['file_size_bytes'] ?? 0;
                                if ($size >= 1073741824) {
                                    echo number_format($size / 1073741824, 2) . ' GB';
                                } elseif ($size >= 1048576) {
                                    echo number_format($size / 1048576, 1) . ' MB';
                                } elseif ($size >= 1024) {
                                    echo number_format($size / 1024, 0) . ' KB';
                                } else {
                                    echo $size . ' B';
                                }
                                ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<script>
function setView(view) {
    document.getElementById('archive-grid').classList.toggle('active', view === 'grid');
    document.getElementById('archive-table').classList.toggle('active', view === 'table');
    document.getElementById('btn-grid').classList.toggle('active', view === 'grid');
    document.getElementById('btn-table').classList.toggle('active', view === 'table');
    localStorage.setItem('archivedProjectsView', view);
}

// Restore saved view preference
document.addEventListener('DOMContentLoaded', function() {
    const savedView = localStorage.getItem('archivedProjectsView') || 'grid';
    setView(savedView);
});
</script>
</body>
</html>
