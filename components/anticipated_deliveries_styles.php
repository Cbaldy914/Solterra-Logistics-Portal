<style>
        /* ==================== CSS CUSTOM PROPERTIES ==================== */
        :root {
            --primary: #488C9A;
            --primary-dark: #3A6E7F;
            --primary-light: rgba(72, 140, 154, 0.08);
            --accent: #E07F3A;
            --accent-dark: #c76a2e;
            --dark: #293E4C;
            --success: #28a745;
            --danger: #dc3545;
            --port: #17a2b8;
            --gray-50: #f8f9fa;
            --gray-100: #f0f4f5;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --shadow-sm: 0 2px 8px rgba(41, 62, 76, 0.04);
            --shadow-md: 0 8px 32px rgba(41, 62, 76, 0.08);
            --shadow-lg: 0 16px 48px rgba(41, 62, 76, 0.12);
            --shadow-xl: 0 24px 64px rgba(41, 62, 76, 0.16);
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.3);
            --transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ==================== BASE ==================== */
        body {
            background: linear-gradient(160deg, #f0f4f5 0%, #e4ecef 40%, #f5f0eb 100%);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        /* ==================== PAGE HEADER ==================== */
        .page-header {
            background: var(--glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            padding: 28px 36px;
            margin-bottom: 32px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--glass-border);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-dark) 100%);
            opacity: 0.9;
        }

        .page-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(72, 140, 154, 0.06) 0%, transparent 70%);
            pointer-events: none;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .header-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.35);
            position: relative;
        }

        .header-icon::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 19px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.3), transparent);
            z-index: -1;
        }

        .header-info h1 {
            font-size: 1.75em;
            font-weight: 700;
            background: linear-gradient(135deg, var(--dark) 0%, var(--primary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 4px 0;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .header-subtitle {
            color: var(--gray-600);
            font-size: 0.95em;
            font-weight: 500;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-subtitle a {
            color: var(--primary);
            text-decoration: none;
            transition: color var(--transition);
        }

        .header-subtitle a:hover {
            color: var(--primary-dark);
        }

        .header-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 20px;
            text-align: center;
            min-width: 90px;
            border: 1px solid var(--gray-200);
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            opacity: 0;
            transition: opacity var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.15);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-value {
            font-size: 1.4em;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.7em;
            color: var(--gray-600);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ==================== STEPPER NAVIGATION ==================== */
        .stepper-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 32px;
            padding: 16px 24px;
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            overflow-x: auto;
        }

        .stepper-step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition);
            white-space: nowrap;
            position: relative;
        }

        .stepper-step:hover {
            background: var(--gray-50);
        }

        .stepper-step.active {
            background: linear-gradient(135deg, var(--primary-light), rgba(72, 140, 154, 0.12));
        }

        .stepper-step.active .stepper-number {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .stepper-step.completed .stepper-number {
            background: var(--success);
            color: white;
        }

        .stepper-number {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85em;
            background: var(--gray-200);
            color: var(--gray-600);
            transition: all var(--transition);
            flex-shrink: 0;
        }

        .stepper-label {
            font-weight: 600;
            font-size: 0.9em;
            color: var(--gray-600);
            transition: color var(--transition);
        }

        .stepper-step.active .stepper-label {
            color: var(--primary-dark);
        }

        .stepper-connector {
            width: 40px;
            height: 2px;
            background: var(--gray-300);
            flex-shrink: 0;
            transition: background var(--transition);
        }

        .stepper-connector.completed {
            background: var(--success);
        }

        /* ==================== MAIN LAYOUT ==================== */
        .planner-layout {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        .planner-main {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* ==================== CARD STYLES ==================== */
        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(72, 140, 154, 0.06);
            overflow: hidden;
            transition: all var(--transition);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05em;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .card-title svg {
            color: var(--primary);
        }

        .card-body {
            padding: 24px;
        }

        .card-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85em;
        }

        .summary-value {
            font-weight: 700;
            color: var(--primary);
        }

        .summary-label {
            color: var(--gray-600);
        }

        .summary-divider {
            color: var(--gray-300);
        }

        /* ==================== COLLAPSIBLE SECTIONS ==================== */
        .collapsible-section {
            margin-bottom: 0;
        }

        .timeline-section {
            margin-top: 24px;
        }

        .collapsible-header {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all var(--transition);
            user-select: none;
        }

        .collapsible-header:hover {
            border-color: rgba(72, 140, 154, 0.25);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .collapsible-header.collapsed {
            border-radius: var(--radius-lg);
        }

        .collapsible-header:not(.collapsed) {
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            border-bottom-color: transparent;
            background: linear-gradient(180deg, white 0%, var(--gray-50) 100%);
        }

        .collapsible-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.05em;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .collapsible-title svg {
            color: var(--primary);
            flex-shrink: 0;
        }

        .collapsible-meta {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .route-summary {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 0.82em;
            color: var(--gray-700);
        }

        .route-step {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            color: var(--dark);
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 600;
            white-space: nowrap;
        }

        .route-arrow {
            color: var(--gray-400);
            font-weight: 600;
        }

        .route-costs,
        .timeline-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .summary-chip {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.78em;
            font-weight: 600;
            white-space: nowrap;
        }

        .summary-chip.hidden {
            display: none;
        }

        .collapsible-badge {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.14) 100%);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82em;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .collapsible-toggle {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all var(--transition);
        }

        .collapsible-header:hover .collapsible-toggle {
            background: var(--primary-light);
            border-color: rgba(72, 140, 154, 0.2);
            color: var(--primary);
        }

        .collapsible-toggle svg {
            transition: transform var(--transition);
        }

        .collapsible-header.collapsed .collapsible-toggle svg {
            transform: rotate(-90deg);
        }

        .collapsible-content {
            background: white;
            border: 1px solid var(--gray-200);
            border-top: none;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            max-height: 8000px;
            transition: max-height 0.5s ease, opacity 0.3s ease;
            opacity: 1;
        }

        .collapsible-content.collapsed {
            max-height: 0;
            opacity: 0;
            border: none;
        }

        .collapsible-inner {
            padding: 24px;
        }

        /* ==================== JOURNEY PLANNER ==================== */
        .journey-planner {
            padding: 28px;
        }

        .journey-intro {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.06) 0%, rgba(224, 127, 58, 0.04) 100%);
            border-radius: var(--radius-md);
            padding: 18px 22px;
            margin-bottom: 28px;
            color: var(--gray-700);
            font-size: 0.92em;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            border: 1px solid rgba(72, 140, 154, 0.1);
        }

        .journey-intro svg {
            color: var(--primary);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .journey-flow {
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
        }

        .journey-node {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            position: relative;
        }

        .journey-node-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            width: 50px;
        }

        .journey-node-dot {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.85em;
            z-index: 2;
            position: relative;
            transition: transform var(--transition);
        }

        .journey-node:hover .journey-node-dot {
            transform: scale(1.1);
        }

        .journey-node-dot.origin {
            background: linear-gradient(135deg, #28a745 0%, #1e8449 100%);
            box-shadow: 0 4px 16px rgba(40, 167, 69, 0.35);
        }

        .journey-node-dot.warehouse {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            box-shadow: 0 4px 16px rgba(224, 127, 58, 0.35);
        }

        .journey-node-dot.port {
            background: linear-gradient(135deg, var(--port) 0%, #138496 100%);
            box-shadow: 0 4px 16px rgba(23, 162, 184, 0.35);
        }

        .journey-node-dot.destination {
            background: linear-gradient(135deg, var(--danger) 0%, #b02a37 100%);
            box-shadow: 0 4px 16px rgba(220, 53, 69, 0.35);
        }

        .journey-connector {
            width: 3px;
            flex-grow: 1;
            background: linear-gradient(180deg, var(--primary) 0%, rgba(72, 140, 154, 0.2) 100%);
            min-height: 24px;
            margin: 4px 0;
            border-radius: 2px;
        }

        .journey-node-content {
            flex: 1;
            padding-bottom: 20px;
            min-width: 0;
        }

        .journey-node-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all var(--transition);
        }

        .journey-node-card:hover {
            border-color: rgba(72, 140, 154, 0.3);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.1);
        }

        .journey-node-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .journey-node-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1.05em;
            margin: 0;
        }

        .journey-node-type {
            font-size: 0.78em;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 3px;
        }

        .journey-node-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .journey-badge {
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 0.72em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .journey-badge.milestone {
            background: rgba(72, 140, 154, 0.1);
            color: var(--primary);
        }

        .journey-badge.customs {
            background: rgba(255, 193, 7, 0.12);
            color: #856404;
        }

        .journey-node-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
        }

        /* Journey Leg Card */
        .journey-leg-card {
            background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin: -8px 0 12px 70px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            transition: all var(--transition);
        }

        .journey-leg-card:hover {
            border-color: rgba(72, 140, 154, 0.2);
            box-shadow: var(--shadow-sm);
        }

        .journey-leg-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: white;
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            transition: all var(--transition);
        }

        .journey-leg-card:hover .journey-leg-icon {
            background: var(--primary-light);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .journey-leg-details {
            flex: 1;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .journey-leg-field {
            min-width: 100px;
        }

        .journey-leg-field label {
            font-size: 0.7em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--gray-600);
            display: block;
            margin-bottom: 4px;
        }

        .journey-leg-field .value {
            font-weight: 600;
            color: var(--dark);
        }

        .journey-add-stop {
            margin: 8px 0 12px 70px;
        }

        .journey-add-stop-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.04) 0%, rgba(72, 140, 154, 0.08) 100%);
            border: 2px dashed rgba(72, 140, 154, 0.25);
            border-radius: var(--radius-sm);
            color: var(--primary);
            font-weight: 600;
            font-size: 0.9em;
            cursor: pointer;
            transition: all var(--transition);
            width: 100%;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
        }

        .journey-add-stop-btn:hover {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.15) 100%);
            border-color: rgba(72, 140, 154, 0.4);
            transform: translateY(-1px);
        }

        /* ==================== WAREHOUSE STOP CARD ==================== */
        .warehouse-stop-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            overflow: hidden;
            transition: all var(--transition);
        }

        .warehouse-stop-card:hover {
            border-color: rgba(72, 140, 154, 0.25);
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.08);
        }

        .warehouse-stop-header {
            padding: 16px 20px;
            background: var(--gray-50);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            transition: background var(--transition);
        }

        .warehouse-stop-header:hover {
            background: var(--gray-100);
        }

        .warehouse-stop-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .warehouse-stop-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .warehouse-stop-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 1em;
            margin: 0;
        }

        .warehouse-stop-type-badge {
            font-size: 0.68em;
            padding: 3px 10px;
            border-radius: 10px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.4px;
        }

        .warehouse-stop-type-badge.warehouse {
            background: rgba(224, 127, 58, 0.12);
            color: var(--accent-dark);
        }

        .warehouse-stop-type-badge.port {
            background: rgba(23, 162, 184, 0.12);
            color: #138496;
        }

        .warehouse-stop-type-badge.customs {
            background: rgba(111, 66, 193, 0.12);
            color: #5a33a1;
        }

        .warehouse-stop-summary {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 0.82em;
            color: var(--gray-600);
        }

        .warehouse-stop-summary-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .warehouse-stop-summary-item svg {
            width: 13px;
            height: 13px;
            color: var(--primary);
        }

        .warehouse-stop-summary-item strong {
            color: var(--dark);
        }

        .warehouse-stop-toggle {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: white;
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all var(--transition);
            cursor: pointer;
            flex-shrink: 0;
        }

        .warehouse-stop-toggle:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .warehouse-stop-toggle svg {
            transition: transform var(--transition);
        }

        .warehouse-stop-card.collapsed .warehouse-stop-toggle svg {
            transform: rotate(-90deg);
        }

        .warehouse-stop-body {
            padding: 20px;
            border-top: 1px solid var(--gray-200);
            transition: all var(--transition);
        }

        .warehouse-stop-card.collapsed .warehouse-stop-body {
            display: none;
        }

        .journey-flow .warehouse-card,
        .journey-planner .warehouse-card {
            width: auto;
            border: none;
            border-radius: 0;
        }

        /* ==================== LOGISTICS VIEW TOGGLE ==================== */
        .logistics-view-toggle {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 20px 24px 0;
            margin-bottom: -8px;
        }

        .view-toggle-btn {
            padding: 9px 18px;
            border: 1.5px solid var(--gray-200);
            background: white;
            color: var(--gray-600);
            font-size: 0.85em;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all var(--transition);
            position: relative;
        }

        .view-toggle-btn:first-child {
            border-radius: var(--radius-sm) 0 0 var(--radius-sm);
            border-right: none;
        }

        .view-toggle-btn:nth-child(2) {
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
        }

        .view-toggle-btn:hover {
            background: var(--gray-50);
            color: var(--primary);
        }

        .view-toggle-btn.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.25);
        }

        .view-toggle-btn.active + .view-toggle-btn {
            border-left-color: var(--primary);
        }

        .view-toggle-actions {
            margin-left: auto;
        }

        .logistics-view {
            display: none;
        }

        .logistics-view.active {
            display: block;
        }

        /* ==================== MAP SECTION ==================== */

        .route-map-container {
            height: 500px;
            position: relative;
            background: linear-gradient(135deg, #e8eef0 0%, #f0f4f5 100%);
            transition: height var(--transition);
        }

        #routeMap {
            width: 100%;
            height: 100%;
        }

        .map-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--gray-500);
        }

        .map-placeholder svg {
            color: var(--gray-300);
            margin-bottom: 12px;
            opacity: 0.6;
        }

        .map-placeholder p {
            font-size: 0.95em;
            margin: 0;
        }

        .map-legend {
            padding: 14px 24px;
            background: linear-gradient(180deg, var(--gray-50) 0%, white 100%);
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            font-size: 0.82em;
            border-top: 1px solid var(--gray-200);
            align-items: center;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-700);
        }

        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }

        .legend-dot.origin { background: var(--success); }
        .legend-dot.warehouse { background: var(--accent); }
        .legend-dot.destination { background: var(--danger); }

        .legend-line {
            width: 24px;
            height: 3px;
            background: var(--primary);
            border-radius: 2px;
            position: relative;
        }

        .legend-line::after {
            content: '';
            position: absolute;
            right: -2px;
            top: -2px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--primary);
        }

        .map-stats-overlay {
            position: absolute;
            top: 16px;
            left: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 10;
        }

        .map-stat-chip {
            background: white;
            border-radius: 20px;
            padding: 8px 14px;
            font-size: 0.78em;
            font-weight: 600;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
        }

        .map-stat-chip .chip-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        /* Google Maps InfoWindow overrides */
        .gm-style-iw-c {
            padding: 0 !important;
            border-radius: 14px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18) !important;
            overflow: hidden !important;
        }

        .gm-style-iw-d {
            overflow: visible !important;
            padding: 0 !important;
        }

        .gm-style-iw-tc::after {
            background: white !important;
        }

        .gm-style-iw-chr {
            position: absolute !important;
            top: 8px !important;
            right: 8px !important;
            height: auto !important;
            padding: 0 !important;
            margin: 0 !important;
            z-index: 10 !important;
        }

        .gm-style-iw-chr button {
            width: 26px !important;
            height: 26px !important;
            background: rgba(255,255,255,0.3) !important;
            border-radius: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
        }

        .gm-style-iw-chr button:hover {
            background: rgba(255,255,255,0.5) !important;
        }

        .gm-style-iw-chr button span {
            background-color: white !important;
            margin: 0 !important;
        }

        /* ==================== TIMELINE & COSTS ==================== */
        .timeline-tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid var(--gray-200);
            padding: 0 24px;
            background: var(--gray-50);
        }

        .timeline-tab {
            padding: 14px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--gray-600);
            font-weight: 600;
            font-size: 0.88em;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Poppins', sans-serif;
            margin-bottom: -2px;
        }

        .timeline-tab:hover {
            color: var(--primary);
            background: rgba(72, 140, 154, 0.04);
        }

        .timeline-tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: white;
        }

        .timeline-panel {
            display: none;
        }

        .timeline-panel.active {
            display: block;
        }

        .timeline-cumulative {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            padding: 20px 24px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.04) 0%, rgba(72, 140, 154, 0.08) 100%);
            border-top: 1px solid var(--gray-200);
            gap: 16px;
        }

        .cumulative-item {
            text-align: center;
            padding: 12px;
            background: white;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-200);
            transition: all var(--transition);
        }

        .cumulative-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .cumulative-label {
            font-size: 0.7em;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .cumulative-value {
            font-size: 1.15em;
            font-weight: 700;
            color: var(--primary);
        }

        /* Monthly Forecast */
        .monthly-forecast-container {
            padding: 24px;
        }

        .monthly-chart-wrapper {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }

        .monthly-chart-legend {
            display: flex;
            justify-content: center;
            gap: 24px;
            flex-wrap: wrap;
            padding: 14px 0;
            border-top: 1px solid var(--gray-200);
        }

        .legend-item-chart {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82em;
            color: var(--gray-700);
        }

        .legend-color {
            width: 18px;
            height: 4px;
            border-radius: 2px;
        }

        .legend-color.freight { background: var(--primary); }
        .legend-color.warehousing { background: var(--accent); }
        .legend-color.milestone { background: var(--success); }
        .legend-color.total { background: var(--dark); }

        /* Weekly Projections Table */
        .weekly-projections-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
        }

        .weekly-projections-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
        }

        .weekly-projections-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .weekly-projections-title svg {
            color: var(--primary);
        }

        .weekly-projections-toggle {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all var(--transition);
            flex-shrink: 0;
        }

        .weekly-projections-header:hover .weekly-projections-toggle {
            background: var(--primary-light);
            border-color: rgba(72, 140, 154, 0.2);
            color: var(--primary);
        }

        .weekly-projections-toggle svg {
            transition: transform var(--transition);
        }

        .weekly-projections-section.collapsed .weekly-projections-toggle svg {
            transform: rotate(-90deg);
        }

        .weekly-projections-content {
            margin-top: 16px;
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease;
            opacity: 1;
        }

        .weekly-projections-content.collapsed {
            max-height: 0;
            opacity: 0;
            margin-top: 0;
        }

        .weekly-table-wrapper {
            overflow-x: auto;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-200);
        }

        .weekly-projections-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88em;
        }

        .weekly-projections-table th,
        .weekly-projections-table td {
            padding: 12px 16px;
            text-align: right;
            border-bottom: 1px solid var(--gray-200);
        }

        .weekly-projections-table th:first-child,
        .weekly-projections-table td:first-child { text-align: left; }
        .weekly-projections-table th:nth-child(2),
        .weekly-projections-table td:nth-child(2) { text-align: left; }

        .weekly-projections-table thead th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--dark);
            text-transform: uppercase;
            font-size: 0.75em;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
        }

        .weekly-projections-table tbody tr {
            transition: background var(--transition);
        }

        .weekly-projections-table tbody tr:hover {
            background: rgba(72, 140, 154, 0.03);
        }

        .weekly-projections-table tbody tr:last-child td { border-bottom: none; }
        .weekly-projections-table .week-number { font-weight: 600; color: var(--primary); }
        .weekly-projections-table .date-range { font-size: 0.85em; color: var(--gray-600); }
        .weekly-projections-table .amount { font-weight: 500; }
        .weekly-projections-table .amount.freight { color: var(--primary); }
        .weekly-projections-table .amount.warehousing { color: var(--accent); }
        .weekly-projections-table .amount.milestone { color: var(--success); }
        .weekly-projections-table .weekly-total { font-weight: 600; color: var(--dark); }
        .weekly-projections-table .cumulative { font-weight: 700; color: var(--accent); }
        .weekly-projections-table .totals-row td {
            font-weight: 700;
            background: var(--gray-50);
        }

        .weekly-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-600);
        }

        .weekly-empty-state svg { margin-bottom: 12px; }
        .weekly-empty-state p { margin: 0; font-size: 0.95em; }

        .weekly-note {
            margin-top: 12px;
            font-size: 0.82em;
            color: var(--gray-600);
            text-align: right;
        }

        /* ==================== BUTTONS ==================== */
        .btn {
            padding: 11px 22px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.15) 0%, transparent 50%);
            pointer-events: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(72, 140, 154, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-200);
        }

        .btn-secondary:hover {
            background: var(--gray-200);
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, #1e8449 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(40, 167, 69, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b02a37 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(220, 53, 69, 0.3);
        }

        .btn-orange {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(224, 127, 58, 0.3);
        }

        .btn-sm {
            padding: 7px 14px;
            font-size: 0.82em;
        }

        .btn-icon {
            padding: 10px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ==================== FORM STYLES ==================== */
        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 6px;
            font-size: 0.88em;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
            transition: all var(--transition);
            background: white;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-help {
            font-size: 0.82em;
            color: var(--gray-600);
            margin-top: 6px;
        }

        .delivery-input,
        .delivery-select,
        .delivery-textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-family: 'Poppins', sans-serif;
            font-size: 0.9em;
            background: white;
            transition: all var(--transition);
        }

        .delivery-input:focus,
        .delivery-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.08);
        }

        .delivery-input[readonly] {
            background: var(--gray-50);
            color: var(--gray-600);
            border-color: var(--gray-200);
        }

        .delivery-field label {
            font-size: 0.72em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--gray-600);
            display: block;
            margin-bottom: 5px;
        }

        /* ==================== MODAL ==================== */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active { display: flex; }

        .modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(41, 62, 76, 0.5);
            backdrop-filter: blur(6px);
        }

        .modal-content {
            position: relative;
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.15em;
            color: var(--dark);
        }

        .modal-close {
            width: 34px;
            height: 34px;
            border: none;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--gray-200);
            color: var(--dark);
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: var(--gray-50);
        }

        /* ==================== TOASTS ==================== */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toast {
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 20px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 280px;
            animation: toastIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(100%) scale(0.8); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }

        .toast-success { border-left: 4px solid var(--success); }
        .toast-error { border-left: 4px solid var(--danger); }
        .toast-info { border-left: 4px solid var(--primary); }

        .toast-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toast-success .toast-icon { background: rgba(40, 167, 69, 0.1); color: var(--success); }
        .toast-error .toast-icon { background: rgba(220, 53, 69, 0.1); color: var(--danger); }
        .toast-info .toast-icon { background: rgba(72, 140, 154, 0.1); color: var(--primary); }
        .toast-message { flex: 1; font-size: 0.9em; color: var(--dark); }

        /* ==================== LOADING ==================== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(8px);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
        }

        .loading-overlay.active { display: flex; }

        .loading-spinner {
            width: 44px;
            height: 44px;
            border: 3px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-text {
            font-size: 1em;
            color: var(--dark);
            font-weight: 500;
        }

        /* ==================== EMPTY STATES ==================== */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--gray-600);
        }

        .empty-state svg { color: var(--gray-300); margin-bottom: 16px; }
        .empty-state h3 { font-size: 1.2em; color: var(--dark); margin: 0 0 8px; }
        .empty-state p { margin: 0 0 20px; }

        .empty-projection {
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.03) 0%, rgba(72, 140, 154, 0.07) 100%);
            border: 2px dashed rgba(72, 140, 154, 0.2);
            border-radius: var(--radius-xl);
            padding: 60px 40px;
            text-align: center;
        }

        .empty-projection-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 12px 32px rgba(72, 140, 154, 0.3);
        }

        .empty-projection h3 { font-size: 1.5em; color: var(--dark); margin: 0 0 12px; }
        .empty-projection p { color: var(--gray-600); margin: 0 0 28px; max-width: 420px; margin-left: auto; margin-right: auto; }

        .getting-started-steps {
            display: flex;
            justify-content: center;
            gap: 28px;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .getting-started-step {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--gray-700);
            font-size: 0.92em;
        }

        .step-number {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, var(--primary-light), rgba(72, 140, 154, 0.15));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--primary);
            font-size: 0.9em;
        }

        /* ==================== READ-ONLY BANNER ==================== */
        .readonly-banner {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: var(--radius-md);
            padding: 14px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: #856404;
        }

        .readonly-banner svg { flex-shrink: 0; }
        .readonly-banner strong { display: block; margin-bottom: 2px; }

        /* ==================== TEMPLATE SELECTOR ==================== */
        .template-selector {
            background: linear-gradient(135deg, rgba(224, 127, 58, 0.05) 0%, rgba(224, 127, 58, 0.1) 100%);
            border: 1px dashed rgba(224, 127, 58, 0.4);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .template-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--accent-dark);
        }

        .template-info svg { flex-shrink: 0; }

        .template-actions {
            display: flex;
            gap: 10px;
        }

        .template-dropdown {
            padding: 10px 16px;
            border: 1.5px solid var(--accent);
            border-radius: var(--radius-sm);
            font-size: 0.9em;
            font-family: 'Poppins', sans-serif;
            background: white;
            color: var(--dark);
            cursor: pointer;
            min-width: 200px;
        }

        .template-dropdown:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(224, 127, 58, 0.15);
        }

        /* ==================== FEE TABLE ==================== */
        .fee-table-journey {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .fee-table-journey th {
            background: var(--gray-50);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.75em;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid var(--gray-200);
        }

        .fee-table-journey td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .fee-table-journey tr:last-child td { border-bottom: none; }

        .fee-table-journey input,
        .fee-table-journey select {
            width: 100%;
            padding: 8px 10px;
            border: 1.5px solid var(--gray-200);
            border-radius: 6px;
            font-size: 0.88em;
            transition: all 0.2s ease;
            font-family: 'Poppins', sans-serif;
        }

        .fee-table-journey input:focus,
        .fee-table-journey select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.08);
        }

        .fee-table-journey .fee-amount-col { width: 100px; }
        .fee-table-journey .fee-action-col { width: 40px; text-align: center; }

        .fee-remove-btn {
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .fee-remove-btn:hover {
            background: rgba(220, 53, 69, 0.08);
        }

        /* Cadence & Transport fields */
        .cadence-field {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .cadence-field input { width: 60px !important; }
        .cadence-field span { font-size: 0.82em; color: var(--gray-600); }

        .truck-info-badge {
            font-size: 0.72em;
            padding: 3px 8px;
            background: rgba(72, 140, 154, 0.08);
            color: var(--primary);
            border-radius: 10px;
            font-weight: 600;
        }

        .end-date-display {
            font-size: 0.82em;
            color: var(--success);
            font-weight: 600;
        }

        /* ==================== COMPARISON PANEL ==================== */
        .comparison-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            overflow: hidden;
            margin-top: 24px;
        }

        .comparison-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .comparison-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1em;
            font-weight: 600;
            color: var(--dark);
            margin: 0;
        }

        .comparison-title svg { color: var(--primary); }

        .comparison-body { padding: 20px; }

        .comparison-table {
            width: 100%;
            border-collapse: collapse;
        }

        .comparison-table th,
        .comparison-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        .comparison-table th {
            font-size: 0.75em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            font-weight: 600;
            background: var(--gray-50);
        }

        .comparison-table td { font-size: 0.92em; }

        .comparison-table tr:last-child td {
            border-bottom: none;
            font-weight: 600;
            background: rgba(72, 140, 154, 0.04);
        }

        .variance-positive { color: var(--success); }
        .variance-negative { color: var(--danger); }

        .progress-indicator {
            padding: 16px 20px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.04) 0%, rgba(72, 140, 154, 0.08) 100%);
            border-top: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .progress-bar-container {
            flex: 1;
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .progress-text {
            font-size: 0.85em;
            color: var(--gray-700);
            white-space: nowrap;
        }

        /* ==================== GOOGLE PLACES ==================== */
        .address-input-wrapper { position: relative; }
        .address-input-wrapper input { width: 100%; padding-right: 36px; }

        .address-input-wrapper .address-loading {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            border: 2px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        .address-input-wrapper.loading .address-loading { display: block; }

        .address-verified {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--success);
            display: none;
        }

        .address-input-wrapper.verified .address-verified { display: block; }

        .pac-container {
            font-family: 'Poppins', sans-serif;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            margin-top: 4px;
            z-index: 10000;
        }

        .pac-item {
            padding: 10px 14px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .pac-item:hover { background: rgba(72, 140, 154, 0.06); }
        .pac-item-selected { background: rgba(72, 140, 154, 0.1); }
        .pac-item-query { font-weight: 500; color: var(--dark); }

        .address-error {
            color: var(--danger);
            font-size: 0.82em;
            margin-top: 4px;
            display: none;
        }

        .address-input-wrapper.error .address-error { display: block; }
        .address-input-wrapper.error input { border-color: var(--danger); }

        /* ==================== AUTOSAVE ==================== */
        .autosave-indicator {
            position: fixed;
            bottom: 24px;
            left: 24px;
            background: white;
            border-radius: var(--radius-sm);
            padding: 10px 16px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85em;
            z-index: 100;
            opacity: 0;
            transform: translateY(20px);
            transition: all var(--transition);
        }

        .autosave-indicator.visible { opacity: 1; transform: translateY(0); }
        .autosave-indicator.saving { color: var(--accent); }
        .autosave-indicator.saved { color: var(--success); }

        .autosave-spinner {
            width: 14px;
            height: 14px;
            border: 2px solid var(--gray-200);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        /* ==================== RESPONSIVE ==================== */
        @media (max-width: 1200px) {
            .route-map-container { height: 400px; }
            .timeline-cumulative { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-stats {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
            }

            .stat-card {
                min-width: 0;
                padding: 10px;
            }

            .stat-value { font-size: 1.2em; }

            .stepper-nav {
                padding: 12px 16px;
                justify-content: flex-start;
            }

            .stepper-step {
                padding: 10px 14px;
            }

            .stepper-connector { width: 20px; }

            .form-row { grid-template-columns: 1fr; }

            .modal-content {
                max-height: 100vh;
                border-radius: 0;
            }

            .journey-leg-card {
                margin-left: 50px;
            }

            .journey-add-stop {
                margin-left: 50px;
            }

            .timeline-cumulative { grid-template-columns: 1fr 1fr; }

            .route-map-container { height: 300px; }

            .page-header { padding: 20px 20px; }

            .monthly-chart-wrapper { height: 220px; }

            .planner-layout { gap: 20px; }
        }

        @media (max-width: 480px) {
            .header-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .getting-started-steps {
                flex-direction: column;
                align-items: center;
            }

            .journey-leg-card {
                margin-left: 30px;
                padding: 12px;
            }

            .journey-add-stop { margin-left: 30px; }

            .journey-leg-details { gap: 12px; }
        }

        /* ==================== FULLSCREEN MAP ==================== */
        .map-fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 5000;
            margin: 0;
            border-radius: 0;
            background: white;
        }

        .map-fullscreen .route-map-container {
            height: calc(100vh - 50px) !important;
        }

        .map-fullscreen .map-fullscreen-close {
            display: flex;
        }

        .map-fullscreen-close {
            display: none;
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 10;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: white;
            border: 1px solid var(--gray-200);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow-md);
            color: var(--gray-700);
            transition: all var(--transition);
        }

        .map-fullscreen-close:hover {
            background: var(--gray-50);
            transform: scale(1.1);
        }

        /* ==================== MODULE ALLOCATION ITEMS ==================== */
        .module-allocation-item {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            transition: all var(--transition);
            overflow: hidden;
        }

        .module-allocation-item:hover {
            border-color: rgba(72, 140, 154, 0.2);
            box-shadow: var(--shadow-sm);
        }

        .allocation-header {
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            gap: 12px;
        }

        .allocation-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 0.88em;
        }

        .vendor-name {
            font-weight: 600;
            color: var(--dark);
        }

        .summary-stat {
            color: var(--gray-600);
        }

        .contract-value {
            color: var(--primary) !important;
            font-weight: 600 !important;
        }

        .allocation-toggle {
            transition: transform var(--transition);
            color: var(--gray-500);
        }

        .allocation-details {
            padding: 0 18px 18px;
            border-top: 1px solid var(--gray-200);
        }

        .module-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            padding-top: 14px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .info-item.full-width {
            grid-column: 1 / -1;
        }

        .info-label {
            font-size: 0.72em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--gray-600);
        }

        .info-value {
            font-weight: 500;
            color: var(--dark);
            font-size: 0.92em;
        }

        .allocation-actions {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--gray-100);
        }

        /* ==================== SAVED INDICATOR ==================== */
        .saved-indicator {
            display: inline-flex;
            align-items: center;
        }

        /* ==================== TOOLTIP ==================== */
        .tooltip-wrapper { position: relative; display: inline-flex; }
        .tooltip-trigger { cursor: help; color: var(--gray-600); }

        .tooltip-content {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark);
            color: white;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            font-size: 0.82em;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
            z-index: 100;
        }

        .tooltip-content::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: var(--dark);
        }

        .tooltip-wrapper:hover .tooltip-content { opacity: 1; visibility: visible; }
    </style>
