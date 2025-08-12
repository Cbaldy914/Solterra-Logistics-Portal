<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

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
    die("You do not have access to this project.");
}

// Define the folders
$folders = [
    ['name' => 'Invoices', 'link' => 'invoices?project_id=' . $project_id],
    ['name' => 'PODs', 'link' => 'pods?project_id=' . $project_id],
    ['name' => 'Flash Test Data', 'link' => 'ftd?project_id=' . $project_id],
    ['name' => 'Bills of Lading', 'link' => 'bills_of_lading?project_id=' . $project_id],
    ['name' => 'Warehousing', 'link' => 'warehousing_docs?project_id=' . $project_id],
    ['name' => 'Modules', 'link' => 'modules_docs?project_id=' . $project_id],
    ['name' => 'Delivery Packet', 'link' => 'delivery_packet?project_id=' . $project_id],
    ['name' => 'Incident Reports', 'link' => 'incident_reports?project_id=' . $project_id],
    ['name' => 'Safe Harbor Evidence', 'link' => 'safe_harbor_evidence?project_id=' . $project_id],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            margin-top: 10px;
            font-size: 0.95em;
            color: #6c757d;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        .breadcrumb a:hover {
            color: #293E4C;
        }
        .breadcrumb .separator {
            margin: 0 12px;
            color: #d1d5db;
            font-weight: 300;
        }

        /* Project Document Header */
        .project-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .project-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .project-info-container {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }

        .project-icon-large {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
            flex-shrink: 0;
        }

        .project-details {
            flex: 1;
            min-width: 250px;
        }

        .project-name {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 12px 0;
            line-height: 1.2;
        }

        .project-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0 0 16px 0;
        }

        .project-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .project-stat {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(72, 140, 154, 0.08);
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.9em;
            font-weight: 500;
            color: #488C9A;
        }

        .project-stat i {
            font-size: 1.1em;
        }

        /* Enhanced Search */
        .search-container {
            position: relative;
            margin-bottom: 40px;
            max-width: 500px;
        }

        .search-input {
            width: 100%;
            padding: 16px 20px 16px 56px;
            font-size: 16px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 16px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            font-family: 'Poppins', sans-serif;
            font-weight: 400;
        }

        .search-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 8px 30px rgba(72, 140, 154, 0.2);
            transform: translateY(-1px);
        }

        .search-input::placeholder {
            color: #9ca3af;
            font-weight: 400;
        }

        .search-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 18px;
            pointer-events: none;
            transition: color 0.3s ease;
        }

        .search-input:focus + .search-icon {
            color: #488C9A;
        }

        /* Document Type Grid */
        .document-types-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
        }

        .document-type-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(72, 140, 154, 0.08);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .document-type-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 60px rgba(72, 140, 154, 0.2);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .document-type-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .document-type-card:hover::before {
            opacity: 1;
        }

        .document-card-header {
            padding: 24px 24px 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .document-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            transition: transform 0.3s ease;
        }

        .document-type-card:hover .document-icon {
            transform: scale(1.1) rotate(5deg);
        }

        /* Different colors for different document types */
        .doc-invoices .document-icon { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .doc-pods .document-icon { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .doc-flash-test .document-icon { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .doc-bills .document-icon { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .doc-warehousing .document-icon { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .doc-modules .document-icon { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .doc-delivery .document-icon { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        .doc-incident .document-icon { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .doc-safe-harbor .document-icon { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }

        .document-info {
            flex: 1;
            min-width: 0;
        }

        .document-title {
            font-size: 1.25em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 8px 0;
            text-decoration: none;
            display: block;
            line-height: 1.3;
            transition: color 0.3s ease;
        }

        .document-title:hover {
            color: #488C9A;
        }

        .document-description {
            font-size: 0.9em;
            color: #6c757d;
            margin: 0;
            line-height: 1.4;
        }

        .document-actions {
            padding: 0 24px 24px 24px;
        }

        .document-button {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
        }

        .document-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.4);
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
        }

        .document-button i {
            font-size: 1.1em;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 20px;
            border: 2px dashed rgba(72, 140, 154, 0.2);
            margin-top: 20px;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 4em;
            color: #d1d5db;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5em;
            color: #6c757d;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .empty-state p {
            color: #9ca3af;
            font-size: 1.05em;
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .project-info-container {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }

            .project-icon-large {
                width: 64px;
                height: 64px;
                font-size: 28px;
            }

            .project-name {
                font-size: 1.8em;
            }

            .document-types-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .search-container {
                max-width: 100%;
            }

            .project-stats {
                flex-direction: column;
                gap: 12px;
            }
        }

        /* Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .document-type-card {
            animation: fadeInUp 0.6s ease forwards;
        }

        .document-type-card:nth-child(1) { animation-delay: 0.1s; }
        .document-type-card:nth-child(2) { animation-delay: 0.2s; }
        .document-type-card:nth-child(3) { animation-delay: 0.3s; }
        .document-type-card:nth-child(4) { animation-delay: 0.4s; }
        .document-type-card:nth-child(5) { animation-delay: 0.5s; }
        .document-type-card:nth-child(6) { animation-delay: 0.6s; }
        .document-type-card:nth-child(7) { animation-delay: 0.7s; }
        .document-type-card:nth-child(8) { animation-delay: 0.8s; }
        .document-type-card:nth-child(9) { animation-delay: 0.9s; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Overview</a>
        <span class="separator">&raquo;</span>
        <span>Documents</span>
    </div>

    <div class="project-header">
        <div class="project-info-container">

            <div class="project-details">
                <h1 class="project-name"><?php echo htmlspecialchars($project_name); ?></h1>
                <p class="project-subtitle">Document Management Center</p>
                <div class="project-stats">
                    <div class="project-stat">
                        <i class="fas fa-folder"></i>
                        <span><?php echo count($folders); ?> Document Types</span>
                    </div>
                    <div class="project-stat">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure Access</span>
                    </div>
                    <div class="project-stat">
                        <i class="fas fa-clock"></i>
                        <span>24/7 Available</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="search-container">
        <input type="text" id="folderFilter" class="search-input" placeholder="Search document types...">
        <i class="fas fa-search search-icon"></i>
    </div>

    <div class="document-types-grid" id="folderList">
        <?php 
        $documentTypes = [
            'Invoices' => ['icon' => 'fas fa-file-invoice-dollar', 'class' => 'doc-invoices', 'description' => 'Project invoices and billing documents'],
            'PODs' => ['icon' => 'fas fa-clipboard-check', 'class' => 'doc-pods', 'description' => 'Proof of delivery confirmations'],
            'Flash Test Data' => ['icon' => 'fas fa-bolt', 'class' => 'doc-flash-test', 'description' => 'Module performance test results'],
            'Bills of Lading' => ['icon' => 'fas fa-shipping-fast', 'class' => 'doc-bills', 'description' => 'Shipping and transport documents'],
            'Warehousing' => ['icon' => 'fas fa-warehouse', 'class' => 'doc-warehousing', 'description' => 'Storage and inventory documentation'],
            'Modules' => ['icon' => 'fas fa-microchip', 'class' => 'doc-modules', 'description' => 'Module specifications and certifications'],
            'Delivery Packet' => ['icon' => 'fas fa-box-open', 'class' => 'doc-delivery', 'description' => 'Complete delivery documentation'],
            'Incident Reports' => ['icon' => 'fas fa-exclamation-triangle', 'class' => 'doc-incident', 'description' => 'Safety and incident documentation'],
            'Safe Harbor Evidence' => ['icon' => 'fas fa-gavel', 'class' => 'doc-safe-harbor', 'description' => 'Legal compliance documentation']
        ];
        
        foreach ($folders as $folder): 
            $folderName = $folder['name'];
            $docType = $documentTypes[$folderName] ?? ['icon' => 'fas fa-file', 'class' => 'doc-default', 'description' => 'Project documentation'];
        ?>
            <div class="document-type-card <?php echo $docType['class']; ?>">
                <div class="document-card-header">
                    <div class="document-icon">
                        <i class="<?php echo $docType['icon']; ?>"></i>
                    </div>
                    <div class="document-info">
                        <a href="<?php echo $folder['link']; ?>" class="document-title">
                            <?php echo htmlspecialchars($folder['name']); ?>
                        </a>
                        <p class="document-description">
                            <?php echo $docType['description']; ?>
                        </p>
                    </div>
                </div>
                <div class="document-actions">
                    <a href="<?php echo $folder['link']; ?>" class="document-button">
                        <i class="fas fa-arrow-right"></i>
                        View Documents
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<!-- Enhanced JavaScript for filtering and animations -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('folderFilter');
        const documentCards = document.querySelectorAll('.document-type-card');
        
        // Enhanced search functionality
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase().trim();
            let visibleCount = 0;
            
            documentCards.forEach(function(card, index) {
                const text = card.textContent.toLowerCase();
                const shouldShow = filter === '' || text.includes(filter);
                
                if (shouldShow) {
                    card.style.display = '';
                    card.style.animation = `fadeInUp 0.4s ease forwards`;
                    card.style.animationDelay = `${index * 0.1}s`;
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Show empty state if no results
            const documentGrid = document.getElementById('folderList');
            const existingEmptyState = document.querySelector('.search-empty-state');
            
            if (visibleCount === 0 && filter !== '') {
                if (!existingEmptyState) {
                    const emptyState = document.createElement('div');
                    emptyState.className = 'empty-state search-empty-state';
                    emptyState.style.gridColumn = '1 / -1';
                    emptyState.innerHTML = `
                        <i class="fas fa-search"></i>
                        <h3>No Document Types Found</h3>
                        <p>No document types match your search criteria. Try a different search term.</p>
                    `;
                    documentGrid.appendChild(emptyState);
                }
            } else if (existingEmptyState) {
                existingEmptyState.remove();
            }
        });

        // Add keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.dispatchEvent(new Event('input'));
                this.blur();
            }
        });

        // Add focus animations
        searchInput.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.02)';
        });

        searchInput.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
        });

        // Enhanced hover effects for document cards
        documentCards.forEach(function(card) {
            card.addEventListener('mouseenter', function() {
                this.style.zIndex = '10';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.zIndex = '1';
            });
        });

        // Add click tracking for analytics (optional)
        documentCards.forEach(function(card) {
            const button = card.querySelector('.document-button');
            if (button) {
                button.addEventListener('click', function(e) {
                    const documentType = card.querySelector('.document-title').textContent;
                    console.log(`Accessing documents: ${documentType}`);
                    
                    // Add subtle loading state
                    this.style.opacity = '0.8';
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                });
            }
        });
    });
</script>
</body>
</html>

<?php
$conn->close();
?>
