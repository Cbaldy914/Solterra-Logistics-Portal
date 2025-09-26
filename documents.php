<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Get the user's ID and account ID
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

// Fetch projects associated with the account
$stmt = $conn->prepare("
    SELECT p.id, p.project_name, p.image_url 
    FROM projects p 
    JOIN customer_accounts ca ON p.account_id = ca.id 
    WHERE ca.id = ?
");
$stmt->bind_param("i", $account_id);
$stmt->execute();
$projects_result = $stmt->get_result();
$stmt->close();

// Fetch projects into an array for easier manipulation
$projects = [];
while ($project = $projects_result->fetch_assoc()) {
    $projects[] = $project;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents</title>
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

        /* Document Management Styles */
        .documents-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .documents-title {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 0;
        }

        .documents-title i {
            font-size: 2.2em;
            color: #488C9A;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            padding: 12px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.15);
        }

        .documents-title h1 {
            font-size: 2.5em;
            font-weight: 600;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }

        .documents-stats {
            display: flex;
            align-items: center;
            gap: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 16px 24px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .stat-item {
            text-align: center;
            padding: 0 16px;
            border-right: 1px solid rgba(72, 140, 154, 0.15);
        }

        .stat-item:last-child {
            border-right: none;
        }

        .stat-number {
            font-size: 1.8em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.85em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Enhanced Search */
        .search-container {
            position: relative;
            margin-bottom: 30px;
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

        /* Enhanced Project Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(415px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .project-card {
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

        .project-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 60px rgba(72, 140, 154, 0.2);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .project-card::before {
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

        .project-card:hover::before {
            opacity: 1;
        }

        .project-header {
            padding: 24px 24px 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .project-icon {
            width: 150px;
            height: 110px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: 0 8px 20px rgba(72, 140, 154, 0.3);
            transition: transform 0.3s ease;
            overflow: hidden;
        }

        .project-icon img {
            border-radius: 16px;
        }

        .project-card:hover .project-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .project-info {
            flex: 1;
            min-width: 0;
        }

        .project-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 8px 0;
            text-decoration: none;
            display: block;
            line-height: 1.3;
            word-wrap: break-word;
            transition: color 0.3s ease;
        }

        .project-title:hover {
            color: #488C9A;
        }

        .project-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85em;
            color: #6c757d;
            margin-bottom: 20px;
        }

        .project-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .project-meta i {
            font-size: 0.9em;
            color: #9ca3af;
        }

        .project-actions {
            padding: 0 24px 24px 24px;
        }

        .project-button {
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

        .project-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.4);
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
        }

        .project-button i {
            font-size: 1.1em;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 20px;
            border: 2px dashed rgba(72, 140, 154, 0.2);
            margin-top: 40px;
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
            .documents-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .documents-stats {
                width: 100%;
                justify-content: space-around;
            }

            .documents-title h1 {
                font-size: 2em;
            }

            .projects-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .search-container {
                max-width: 100%;
            }

            .stat-item {
                padding: 0 12px;
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

        .project-card {
            animation: fadeInUp 0.6s ease forwards;
        }

        .project-card:nth-child(1) { animation-delay: 0.1s; }
        .project-card:nth-child(2) { animation-delay: 0.2s; }
        .project-card:nth-child(3) { animation-delay: 0.3s; }
        .project-card:nth-child(4) { animation-delay: 0.4s; }
        .project-card:nth-child(n+5) { animation-delay: 0.5s; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Documents']); ?>

            <div class="documents-header">
            <div class="documents-title">
                <i class="fas fa-folder-open"></i>
                <h1>Documents</h1>
            </div>
            
            <div class="documents-stats">
                <div class="stat-item">
                    <p class="stat-number"><?php echo count($projects); ?></p>
                    <p class="stat-label">Projects</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo count($projects) * 9; ?></p>
                    <p class="stat-label">Document Types</p>
                </div>
                <div class="stat-item">
                    <a href="global_documents" style="text-decoration: none; color: inherit;">
                        <p class="stat-number">🌍</p>
                        <p class="stat-label">Global View</p>
                    </a>
                </div>
            </div>
        </div>

    <div class="search-container">
        <input type="text" id="projectFilter" class="search-input" placeholder="Search projects...">
        <i class="fas fa-search search-icon"></i>
    </div>

    <?php if (count($projects) > 0): ?>
        <div class="projects-grid" id="projectList">
            <?php foreach ($projects as $project): ?>
                <div class="project-card">
                    <div class="project-header">
                        <div class="project-icon">
                            <?php if (!empty($project['image_url']) && file_exists($project['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="<?php echo htmlspecialchars($project['project_name']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 16px;">
                            <?php else: ?>
                                <i class="fas fa-solar-panel"></i>
                            <?php endif; ?>
                        </div>
                        <div class="project-info">
                            <a href="project_documents?project_id=<?php echo $project['id']; ?>" class="project-title">
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </a>
                            <div class="project-meta">
                                <div class="project-meta-item">
                                    <i class="fas fa-folder"></i>
                                    <span>9 Document Types</span>
                                </div>
                                <div class="project-meta-item">
                                    <i class="fas fa-clock"></i>
                                    <span>Recently Updated</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="project-actions">
                        <a href="project_documents?project_id=<?php echo $project['id']; ?>" class="project-button">
                            <i class="fas fa-arrow-right"></i>
                            Access Documents
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>No Projects Available</h3>
            <p>You don't have access to any projects yet. Contact your administrator for access.</p>
        </div>
    <?php endif; ?>
</main>

<!-- Enhanced JavaScript for filtering and animations -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('projectFilter');
        const projectCards = document.querySelectorAll('.project-card');
        
        // Enhanced search functionality
        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase().trim();
            let visibleCount = 0;
            
            projectCards.forEach(function(card, index) {
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
            const projectGrid = document.getElementById('projectList');
            const existingEmptyState = document.querySelector('.search-empty-state');
            
            if (visibleCount === 0 && filter !== '') {
                if (!existingEmptyState) {
                    const emptyState = document.createElement('div');
                    emptyState.className = 'empty-state search-empty-state';
                    emptyState.innerHTML = `
                        <i class="fas fa-search"></i>
                        <h3>No Projects Found</h3>
                        <p>No projects match your search criteria. Try a different search term.</p>
                    `;
                    projectGrid.parentNode.appendChild(emptyState);
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

        // Enhanced hover effects for project cards
        projectCards.forEach(function(card) {
            card.addEventListener('mouseenter', function() {
                this.style.zIndex = '10';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.zIndex = '1';
            });
        });
    });
</script>
</body>
</html>

<?php
$conn->close();
?>
