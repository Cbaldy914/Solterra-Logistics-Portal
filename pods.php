<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for project_id parameter
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// First get the user's account ID
$stmt = $conn->prepare("
    SELECT account_id 
    FROM customer_account_users 
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($account_id);
$stmt->fetch();
$stmt->close();

// Verify that the project belongs to the account
$stmt = $conn->prepare("
    SELECT p.project_name 
    FROM projects p 
    JOIN customer_accounts ca ON p.account_id = ca.id 
    WHERE p.id = ? AND ca.id = ?
");
$stmt->bind_param("ii", $project_id, $account_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project or it does not exist.");
}

// Fetch PODs (and BOL numbers) for this project
$stmt = $conn->prepare("
    SELECT d.id, d.proof_of_delivery, d.bol_number
    FROM deliveries d
    WHERE d.project_id = ? 
      AND d.proof_of_delivery IS NOT NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$pods_result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PODs - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/png">
    <link rel="shortcut icon" href="pictures/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern CSS Reset and Base Styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        /* Breadcrumb Enhancement */
        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
        }
        
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .breadcrumb a:hover {
            color: #3a6e7f;
            text-decoration: underline;
        }
        
        .breadcrumb .separator {
            margin: 0 12px;
            color: #6c757d;
            font-weight: 600;
        }
        
        
        .pod_header h1 {
            margin-bottom: 12px;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* POD Container */
        .pods-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 20px;
        }
        
        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            border: 1px solid rgba(72, 140, 154, 0.1);
        }
        
        .selection-info {
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 500;
            color: #495057;
        }
        
        .select-all-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .select-all-container:hover {
            background: rgba(72, 140, 154, 0.1);
        }
        
        .select-all-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
            cursor: pointer;
        }
        
        .selected-count {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }
        
        /* Download Button */
        .download-btn {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 
                0 4px 15px rgba(72, 140, 154, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .download-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #3a6e7f, #293E4C);
            transform: translateY(-2px);
            box-shadow: 
                0 8px 25px rgba(72, 140, 154, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }
        
        .download-btn:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .download-btn:active:not(:disabled) {
            transform: translateY(0);
        }
        
        /* POD Table */
        .pods-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.08),
                0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .pods-table thead tr {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
        }
        
        .pods-table th {
            color: white;
            padding: 20px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: none;
            position: relative;
        }
        
        .pods-table th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #fbb040, #f39c12);
        }
        
        .pods-table td {
            padding: 16px;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
            transition: all 0.2s ease;
        }
        
        .pods-table tbody tr {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .pods-table tbody tr:hover {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.1);
        }
        
        .pods-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Checkbox Styling */
        .pod-checkbox {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .pod-checkbox:hover {
            transform: scale(1.1);
        }
        
        /* POD File Link */
        .pod-file-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .pod-file-link:hover {
            color: #3a6e7f;
            background: rgba(72, 140, 154, 0.1);
            text-decoration: none;
            transform: translateX(4px);
        }
        
        .pod-file-link::before {
            content: '📄';
            font-size: 1.2em;
        }
        
        /* BOL Number */
        .bol-number {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            color: #495057;
            border: 1px solid #dee2e6;
            display: inline-block;
            transition: all 0.2s ease;
        }
        
        .bol-number-link {
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }
        
        .bol-number-link:hover {
            text-decoration: none;
            transform: translateY(-1px);
        }
        
        .bol-number-link:hover .bol-number {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            border-color: #488C9A;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .bol-number.no-bol {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #6c757d;
            opacity: 0.7;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            color: #495057;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .empty-state p {
            font-size: 1.1rem;
            line-height: 1.6;
        }
        
        /* Loading Modal */
        .loading-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }
        
        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            max-width: 400px;
            width: 90%;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #488C9A;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-content h3 {
            color: #293E4C;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .loading-content p {
            color: #6c757d;
            line-height: 1.5;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            main {
                padding: 15px;
            }
            
            .pods-container {
                padding: 20px;
                border-radius: 16px;
            }
            
            .action-bar {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }
            
            .selection-info {
                justify-content: center;
            }
            
            .download-btn {
                width: 100%;
                justify-content: center;
            }
            
            .pod_header h1 {
                font-size: 2rem;
            }
            
            .pods-table {
                font-size: 0.9rem;
            }
            
            .pods-table th,
            .pods-table td {
                padding: 12px 8px;
            }
            
            .breadcrumb {
                padding: 10px 15px;
                margin: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .pod_header h1 {
                font-size: 1.8rem;
            }
            
            .pods-table th,
            .pods-table td {
                padding: 10px 6px;
                font-size: 0.85rem;
            }
            
            .bol-number {
                font-size: 0.8rem;
                padding: 4px 8px;
            }
            
            .loading-content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <a href="project_documents.php?project_id=<?php echo $project_id; ?>">Project Documents</a>
        <span class="separator">&raquo;</span>
        <span>PODs</span>
    </div>
    <div class="pod_header">
        <h1>PODs for <?php echo htmlspecialchars($project_name); ?></h1>
    </div>
    
    <?php if ($pods_result->num_rows > 0): ?>
        <div class="pods-container">
            <form action="download_pods" method="post" id="downloadForm">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                
                <!-- Action Bar -->
                <div class="action-bar">
                    <div class="selection-info">
                        <label class="select-all-container">
                            <input type="checkbox" id="select-all">
                            <span>Select All</span>
                        </label>
                        <div class="selected-count" id="selectedCount">0 selected</div>
                    </div>
                    <button type="submit" name="download_selected" class="download-btn" id="downloadBtn" disabled>
                        <span>📥</span>
                        Download Selected PODs
                    </button>
                </div>
                
                <!-- POD Table -->
                <table class="pods-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Select</th>
                            <th>POD File</th>
                            <th style="width: 180px;">BOL Number</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($pod = $pods_result->fetch_assoc()): ?>
                            <tr onclick="toggleCheckbox(this, event)">
                                <td>
                                    <input type="checkbox" name="selected_pods[]" value="<?php echo $pod['id']; ?>" class="pod-checkbox">
                                </td>
                                <td>
                                    <a href="view_pod?delivery_id=<?php echo $pod['id']; ?>" target="_blank" class="pod-file-link" onclick="event.stopPropagation();">
                                        <?php echo htmlspecialchars(basename($pod['proof_of_delivery'])); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($pod['bol_number']): ?>
                                        <a href="view_project.php?project_id=<?php echo $project_id; ?>&delivery_id=<?php echo $pod['id']; ?>" 
                                           class="bol-number-link" 
                                           onclick="event.stopPropagation();"
                                           title="View delivery details">
                                            <span class="bol-number">
                                                <?php echo htmlspecialchars($pod['bol_number']); ?>
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <span class="bol-number no-bol">
                                            No BOL
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </form>
        </div>
    <?php else: ?>
        <div class="pods-container">
            <div class="empty-state">
                <div class="empty-state-icon">📄</div>
                <h3>No PODs Found</h3>
                <p>There are no Proof of Delivery documents available for this project yet.<br>
                PODs will appear here once deliveries are completed and documented.</p>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Loading Modal -->
    <div id="loadingModal" class="loading-modal">
        <div class="loading-content">
            <div class="spinner"></div>
            <h3>Preparing Download...</h3>
            <p>We're compiling your selected PODs into a ZIP file.<br>This may take a few moments depending on file sizes.</p>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllCheckbox = document.getElementById('select-all');
        const podCheckboxes = document.querySelectorAll('input[name="selected_pods[]"]');
        const selectedCountElement = document.getElementById('selectedCount');
        const downloadBtn = document.getElementById('downloadBtn');
        const downloadForm = document.getElementById('downloadForm');
        const loadingModal = document.getElementById('loadingModal');
        
        // Update selected count and button state
        function updateSelectionState() {
            const selectedCount = document.querySelectorAll('input[name="selected_pods[]"]:checked').length;
            const totalCount = podCheckboxes.length;
            
            // Update count display
            selectedCountElement.textContent = `${selectedCount} selected`;
            
            // Update download button state
            downloadBtn.disabled = selectedCount === 0;
            
            // Update select all checkbox state
            if (selectedCount === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (selectedCount === totalCount) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }
        }
        
        // Select/deselect all functionality
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            podCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateSelectionState();
        });
        
        // Individual checkbox change
        podCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectionState);
        });
        
        // Row click to toggle checkbox (but not when clicking the checkbox itself)
        window.toggleCheckbox = function(row, event) {
            // Don't toggle if the click was directly on the checkbox or a link
            if (event && (event.target.type === 'checkbox' || event.target.tagName === 'A' || event.target.closest('a'))) {
                return;
            }
            const checkbox = row.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
            updateSelectionState();
        };
        
        // Form submission with loading modal
        downloadForm.addEventListener('submit', function(e) {
            const selectedCount = document.querySelectorAll('input[name="selected_pods[]"]:checked').length;
            
            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one POD to download.');
                return false;
            }
            
            // Clear any existing download completion cookie
            document.cookie = 'download_complete=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
            
            // Show loading modal
            showLoadingModal();
            
            // Start checking for download completion
            startDownloadCheck();
            
            // Form will submit normally, but we show the loading state
            return true;
        });
        
        // Loading modal functions
        function showLoadingModal() {
            if (loadingModal) {
                loadingModal.style.display = 'block';
            }
        }
        
        function hideLoadingModal() {
            if (loadingModal) {
                loadingModal.style.display = 'none';
            }
        }
        
        // Download completion checker
        function startDownloadCheck() {
            let checkCount = 0;
            const maxChecks = 120; // Check for up to 2 minutes (120 * 1 second)
            
            const checkInterval = setInterval(function() {
                checkCount++;
                
                // Check if download completion cookie exists
                if (document.cookie.indexOf('download_complete=1') !== -1) {
                    // Download completed!
                    clearInterval(checkInterval);
                    hideLoadingModal();
                    
                    // Clean up the cookie
                    document.cookie = 'download_complete=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
                    return;
                }
                
                // Safety timeout
                if (checkCount >= maxChecks) {
                    clearInterval(checkInterval);
                    hideLoadingModal();
                    alert('Download may have completed. If you did not receive the file, please try again.');
                }
            }, 1000); // Check every 1 second
        }
        
        // Hide loading modal when page loads (in case of back button or refresh)
        hideLoadingModal();
        
        // Initialize selection state
        updateSelectionState();
        
        // Handle loading modal timeout (hide after 30 seconds as failsafe)
        let loadingTimeout;
        downloadForm.addEventListener('submit', function() {
            loadingTimeout = setTimeout(function() {
                hideLoadingModal();
                // Optionally show an error message
                alert('Download is taking longer than expected. Please try again or contact support if the issue persists.');
            }, 30000); // 30 seconds
        });
        
        // Clear timeout if page unloads normally
        window.addEventListener('beforeunload', function() {
            if (loadingTimeout) {
                clearTimeout(loadingTimeout);
            }
        });
    });
</script>
</body>
</html>

<?php
$conn->close();
?>
