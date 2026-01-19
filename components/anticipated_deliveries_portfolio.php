<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Planning - Solterra</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 24px;
        }

        .page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
        }

        .page-header h1 {
            font-size: 2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            color: #488C9A;
            -webkit-text-fill-color: #488C9A;
        }

        .page-header p {
            color: #6c757d;
            margin: 0;
            font-size: 1em;
        }

        .coming-soon-banner {
            background: linear-gradient(135deg, #e8f4f6 0%, #d4eef2 100%);
            border: 1px solid #488C9A;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .coming-soon-banner i {
            font-size: 2em;
            color: #488C9A;
        }

        .coming-soon-banner .content h3 {
            margin: 0 0 4px 0;
            color: #293E4C;
            font-size: 1.1em;
        }

        .coming-soon-banner .content p {
            margin: 0;
            color: #5a7a82;
            font-size: 0.9em;
        }

        .projects-section h2 {
            font-size: 1.3em;
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 20px 0;
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

        @media (max-width: 768px) {
            .page-header h1 {
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

<div class="page-container">
    <div class="page-header">
        <h1><i class="fas fa-route"></i> Project Planning</h1>
        <p>Plan delivery schedules and forecast costs for your projects</p>
    </div>

    <div class="coming-soon-banner">
        <i class="fas fa-rocket"></i>
        <div class="content">
            <h3>Enhanced Planning Tool Coming Soon!</h3>
            <p>We're building a comprehensive delivery trip planner with multi-stop routing, warehouse cost configuration, and real-time milestone tracking. Select a project below to use the current scheduling tool.</p>
        </div>
    </div>

    <div class="projects-section">
        <h2>Select a Project</h2>

        <?php if (empty($projects)): ?>
        <div class="empty-state">
            <i class="fas fa-folder-open"></i>
            <h3>No Projects Found</h3>
            <p>Create a project first to start planning deliveries.</p>
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

                    <?php if ($project['has_schedule']): ?>
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
                            <?php echo $project['has_schedule'] ? 'View Plan' : 'Create Plan'; ?>
                            <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
