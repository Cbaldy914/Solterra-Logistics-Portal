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
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
        }

        /* ==================== PAGE HEADER ==================== */
        .page-header {
            background: white;
            border-radius: var(--radius-xl);
            padding: 28px 36px;
            margin-bottom: 24px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            border: 1px solid var(--gray-200);
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
            gap: 16px;
        }

        .planner-main {
            display: flex;
            flex-direction: column;
            gap: 16px;
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
            margin-top: 16px;
        }

        .collapsible-header {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
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
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
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

        /* ==================== SIMPLIFIED COLLAPSED SECTION SUMMARY ==================== */
        .collapsed-summary {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.88em;
        }

        .summary-text {
            color: var(--gray-600);
        }

        .summary-divider {
            color: var(--gray-300);
        }

        .summary-highlight {
            font-weight: 600;
            color: var(--primary);
        }

        .summary-highlight.total {
            color: var(--accent);
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
            overflow: visible;
            max-height: 8000px;
            transition: max-height 0.5s ease, opacity 0.3s ease;
            opacity: 1;
        }

        .collapsible-content.collapsed {
            max-height: 0;
            opacity: 0;
            border: none;
            overflow: hidden;
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
            position: relative;
            z-index: 10;
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

        .logistics-view {
            display: none;
        }

        .logistics-view.active {
            display: block;
        }

        .logistics-section-actions {
            display: flex;
            justify-content: flex-end;
            padding: 12px 24px 24px;
        }

        .logistics-section-actions .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        /* ==================== MAP SECTION ==================== */

        .map-wrapper {
            padding: 20px 24px 24px;
        }

        .route-map-container {
            height: 520px;
            position: relative;
            background: linear-gradient(135deg, #e8eef0 0%, #f0f4f5 100%);
            transition: height var(--transition);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow:
                0 4px 20px rgba(41, 62, 76, 0.08),
                inset 0 0 0 1px rgba(72, 140, 154, 0.1);
        }

        .route-map-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, transparent 100%);
            pointer-events: none;
            z-index: 5;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        #routeMap {
            width: 100%;
            height: 100%;
            border-radius: var(--radius-lg);
        }

        .map-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--gray-500);
            z-index: 10;
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
            padding: 16px 20px;
            margin-top: 16px;
            background: white;
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 0.82em;
        }

        .legend-section {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
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

        .legend-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9em;
            color: var(--gray-500);
            font-style: italic;
        }

        .legend-hint svg {
            color: var(--gray-400);
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

            .planner-layout { gap: 12px; }

            /* Collapsed summary responsive */
            .collapsed-summary {
                flex-wrap: wrap;
                gap: 8px;
            }

            .summary-divider {
                display: none;
            }

            .map-wrapper {
                padding: 16px;
            }

            .map-legend {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .legend-section {
                gap: 12px;
            }
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

            /* Collapsed summary responsive */
            .collapsed-summary {
                font-size: 0.8em;
            }
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

        /* ==================== MODULE ALLOCATION CARDS (Redesigned) ==================== */
        .module-allocation-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            margin-bottom: 20px;
            overflow: hidden;
            transition: all var(--transition);
        }

        .module-allocation-card:hover {
            box-shadow: var(--shadow-lg);
            border-color: rgba(72, 140, 154, 0.2);
        }

        /* Card Header - Manufacturer Info */
        .allocation-card-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 24px;
            background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
            border-bottom: 1px solid var(--gray-100);
        }

        .manufacturer-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.3);
        }

        .manufacturer-info {
            flex: 1;
            min-width: 0;
        }

        .manufacturer-name {
            margin: 0 0 6px;
            font-size: 1.15em;
            font-weight: 700;
            color: var(--dark);
        }

        .manufacturer-address {
            margin: 0;
            font-size: 0.88em;
            color: var(--gray-600);
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }

        .manufacturer-address svg {
            flex-shrink: 0;
            margin-top: 2px;
            color: var(--gray-400);
        }

        .allocation-card-value {
            text-align: right;
            flex-shrink: 0;
        }

        .allocation-card-value .value-amount {
            display: block;
            font-size: 1.4em;
            font-weight: 700;
            color: var(--primary);
        }

        .allocation-card-value .value-label {
            font-size: 0.75em;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Specs Grid */
        .allocation-specs-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1px;
            background: var(--gray-200);
            margin: 0;
        }

        .spec-card {
            background: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .spec-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .spec-wattage .spec-icon {
            background: rgba(255, 193, 7, 0.12);
            color: #d39e00;
        }

        .spec-modules .spec-icon {
            background: rgba(72, 140, 154, 0.1);
            color: var(--primary);
        }

        .spec-pallets .spec-icon {
            background: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
        }

        .spec-trucks .spec-icon {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }

        .spec-content {
            display: flex;
            flex-direction: column;
        }

        .spec-value {
            font-size: 1.3em;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }

        .spec-label {
            font-size: 0.75em;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Packing Config */
        .allocation-packing {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 16px 24px;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-100);
        }

        .packing-item {
            text-align: center;
        }

        .packing-value {
            display: block;
            font-size: 1.1em;
            font-weight: 700;
            color: var(--dark);
        }

        .packing-label {
            font-size: 0.7em;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        .packing-divider, .packing-equals {
            font-size: 1.2em;
            font-weight: 600;
            color: var(--gray-400);
        }

        .packing-result .packing-value {
            color: var(--primary);
        }

        /* Milestones Section */
        .allocation-milestones {
            padding: 20px 24px;
            background: white;
            border-bottom: 1px solid var(--gray-100);
        }

        .milestones-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9em;
            margin-bottom: 14px;
        }

        .milestones-header svg {
            color: var(--primary);
        }

        .milestones-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .milestone-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.03) 100%);
            border: 1px solid rgba(72, 140, 154, 0.15);
            border-radius: 10px;
            padding: 10px 14px;
        }

        .milestone-trigger {
            font-size: 0.85em;
            font-weight: 600;
            color: var(--dark);
        }

        .milestone-pct {
            font-size: 0.9em;
            font-weight: 700;
            color: var(--primary);
            background: rgba(72, 140, 154, 0.1);
            padding: 2px 8px;
            border-radius: 6px;
        }

        .milestone-amount {
            font-size: 0.85em;
            color: var(--gray-600);
        }

        /* Card Actions */
        .allocation-card-actions {
            padding: 16px 24px;
            display: flex;
            justify-content: flex-end;
            background: var(--gray-50);
        }

        .btn-allocation-remove {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: white;
            border: 1px solid #fee2e2;
            border-radius: var(--radius-sm);
            color: #dc2626;
            font-size: 0.85em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-allocation-remove:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .allocation-card-header {
                flex-direction: column;
            }

            .allocation-card-value {
                text-align: left;
                width: 100%;
                padding-top: 12px;
                border-top: 1px dashed var(--gray-200);
                margin-top: 12px;
            }

            .allocation-specs-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .allocation-packing {
                flex-wrap: wrap;
            }
        }

        /* Legacy styles for backwards compatibility */
        .module-allocation-item {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            margin-bottom: 10px;
            transition: all var(--transition);
            overflow: hidden;
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

        /* ==================== FLOW CANVAS - COMPLETE REDESIGN ==================== */

        .flow-canvas-container {
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 50%, #e8f0f3 100%);
            border-radius: var(--radius-lg);
            padding: 0;
            position: relative;
            overflow: hidden;
            min-height: 500px;
        }

        .flow-canvas-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 50% 0%, rgba(72, 140, 154, 0.04) 0%, transparent 40%),
                radial-gradient(circle at 50% 100%, rgba(220, 53, 69, 0.03) 0%, transparent 40%);
            pointer-events: none;
        }

        /* Simple instruction hint */
        .flow-canvas-hint {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(72, 140, 154, 0.1);
            font-size: 0.88em;
            color: var(--gray-600);
        }

        .flow-canvas-hint svg {
            flex-shrink: 0;
            color: var(--primary);
        }

        .flow-canvas-hint strong {
            color: var(--primary);
            font-weight: 600;
        }

        /* Flow Canvas Wrapper */
        .flow-canvas-wrapper {
            position: relative;
            min-height: 450px;
            padding: 50px 40px 80px;
        }

        .flow-connections-svg,
        .flow-drag-line-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 5;
        }

        .flow-drag-line-svg {
            z-index: 100;
        }

        .flow-connection-path {
            fill: none;
            stroke-width: 3;
            stroke-linecap: round;
            stroke: url(#connectionGradient);
            marker-end: url(#arrowhead);
            transition: all 0.25s ease;
            cursor: pointer;
            pointer-events: stroke;
        }

        .flow-connection-path:hover {
            stroke-width: 5;
            stroke: url(#connectionGradientHover);
            marker-end: url(#arrowheadHover);
            filter: drop-shadow(0 0 6px rgba(72, 140, 154, 0.4));
        }

        .flow-connection-label {
            font-size: 11px;
            font-weight: 600;
            fill: var(--gray-600);
        }

        /* Connection info badge on the line */
        .flow-connection-badge {
            position: absolute;
            background: white;
            border: 2px solid var(--primary);
            border-radius: 16px;
            padding: 6px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78em;
            font-weight: 600;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
            box-shadow: 0 2px 12px rgba(72, 140, 154, 0.2);
            transform: translate(-50%, -50%);
        }

        .flow-connection-badge:hover {
            transform: translate(-50%, -50%) scale(1.08);
            box-shadow: 0 4px 20px rgba(72, 140, 154, 0.35);
            border-color: var(--primary-dark);
        }

        .flow-connection-badge svg {
            width: 16px;
            height: 16px;
            color: var(--primary);
        }

        .flow-connection-badge .badge-cost {
            color: var(--primary);
            font-weight: 700;
        }

        /* Floating Add Stop Button */
        .flow-add-stop-fab {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 30px;
            font-size: 0.9em;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(72, 140, 154, 0.4);
            transition: all 0.25s ease;
            z-index: 20;
        }

        .flow-add-stop-fab:hover {
            transform: translateX(-50%) translateY(-2px);
            box-shadow: 0 8px 30px rgba(72, 140, 154, 0.5);
        }

        .flow-add-stop-fab svg {
            transition: transform 0.2s ease;
        }

        .flow-add-stop-fab:hover svg {
            transform: rotate(90deg);
        }

        /* Flow Canvas Grid */
        .flow-canvas {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
        }

        /* Flow Level (Row of nodes at same depth) */
        .flow-level {
            display: flex;
            justify-content: center;
            align-items: flex-start;
            gap: 60px;
            width: 100%;
            position: relative;
        }

        .flow-level-spacer {
            height: 100px;
            position: relative;
        }

        /* Flow Node */
        .flow-node {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .flow-node:hover {
            transform: translateY(-3px);
        }

        .flow-node:hover .flow-node-orb {
            transform: scale(1.05);
        }

        /* Flow Node Orb */
        .flow-node-orb {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .flow-node-orb::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: inherit;
            opacity: 0.3;
            filter: blur(8px);
            z-index: -1;
        }

        .flow-node-orb::after {
            content: '';
            position: absolute;
            inset: 2px;
            border-radius: 50%;
            background: linear-gradient(180deg, rgba(255,255,255,0.4) 0%, transparent 50%);
            pointer-events: none;
        }

        .flow-node-orb svg {
            width: 28px;
            height: 28px;
            color: white;
            position: relative;
            z-index: 1;
        }

        .flow-node-orb.origin {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.4);
        }

        .flow-node-orb.warehouse {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 8px 32px rgba(245, 158, 11, 0.4);
        }

        .flow-node-orb.port {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 8px 32px rgba(6, 182, 212, 0.4);
        }

        .flow-node-orb.customs {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            box-shadow: 0 8px 32px rgba(139, 92, 246, 0.4);
        }

        .flow-node-orb.destination {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 8px 32px rgba(239, 68, 68, 0.4);
        }

        .flow-node-orb.branch-point {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 6px 24px rgba(72, 140, 154, 0.35);
        }

        .flow-node-orb.branch-point svg {
            width: 20px;
            height: 20px;
        }

        /* Connection Ports */
        .flow-node-port {
            position: absolute;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: white;
            border: 3px solid var(--primary);
            cursor: crosshair;
            z-index: 20;
            transition: all 0.2s ease;
            opacity: 0;
        }

        .flow-node:hover .flow-node-port,
        .flow-node-port.active {
            opacity: 1;
        }

        .flow-node-port:hover {
            transform: scale(1.3);
            background: var(--primary);
            box-shadow: 0 0 12px rgba(72, 140, 154, 0.6);
        }

        .flow-node-port.active {
            background: var(--primary);
            transform: scale(1.3);
            box-shadow: 0 0 16px rgba(72, 140, 154, 0.8);
            animation: pulse-port 1s infinite;
        }

        @keyframes pulse-port {
            0%, 100% { box-shadow: 0 0 16px rgba(72, 140, 154, 0.8); }
            50% { box-shadow: 0 0 24px rgba(72, 140, 154, 1); }
        }

        .flow-node-port.port-out {
            bottom: -9px;
            left: 50%;
            transform: translateX(-50%);
        }

        .flow-node-port.port-in {
            top: -9px;
            left: 50%;
            transform: translateX(-50%);
        }

        .flow-node-port.port-out:hover,
        .flow-node-port.port-in:hover {
            transform: translateX(-50%) scale(1.3);
        }

        .flow-node-port.port-out.active,
        .flow-node-port.port-in.active {
            transform: translateX(-50%) scale(1.3);
        }

        /* Port highlight when dragging over */
        .flow-node-port.drop-target {
            background: #10b981;
            border-color: #10b981;
            transform: translateX(-50%) scale(1.5);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.8);
        }

        /* Hide certain ports based on node type */
        .flow-node[data-stop-type="origin"] .flow-node-port.port-in,
        .flow-node[data-stop-type="destination"] .flow-node-port.port-out {
            display: none;
        }

        /* Canvas dragging state */
        .flow-canvas-wrapper.dragging {
            cursor: crosshair;
        }

        .flow-canvas-wrapper.dragging .flow-node-port.port-in {
            opacity: 1;
            animation: pulse-port 1s infinite;
        }

        .flow-canvas-wrapper.dragging .flow-node[data-stop-type="destination"] .flow-node-port.port-in,
        .flow-canvas-wrapper.dragging .flow-node:not([data-is-source="true"]) .flow-node-port.port-in {
            opacity: 1;
        }

        /* Flow Node Card */
        .flow-node-card {
            margin-top: 12px;
            background: white;
            border-radius: var(--radius-md);
            padding: 14px 18px;
            min-width: 180px;
            max-width: 240px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .flow-node:hover .flow-node-card {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            border-color: rgba(72, 140, 154, 0.2);
        }

        .flow-node-card h4 {
            margin: 0 0 4px;
            font-size: 0.95em;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .flow-node-card .node-type {
            font-size: 0.72em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }

        .flow-node-card .node-meta {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .flow-node-card .node-meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.78em;
            color: var(--gray-600);
        }

        .flow-node-card .node-meta-item svg {
            width: 12px;
            height: 12px;
            color: var(--primary);
        }

        .flow-node-card .node-meta-item strong {
            color: var(--primary);
        }

        /* Flow Node Actions */
        .flow-node-actions {
            position: absolute;
            bottom: -40px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 6px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.25s ease;
        }

        .flow-node:hover .flow-node-actions {
            opacity: 1;
            visibility: visible;
            bottom: -46px;
        }

        .flow-node-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Poppins', sans-serif;
        }

        .flow-node-action-btn.add {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.35);
        }

        .flow-node-action-btn.add:hover {
            transform: scale(1.15);
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.45);
        }

        .flow-node-action-btn.edit {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
        }

        .flow-node-action-btn.edit:hover {
            background: var(--gray-50);
            color: var(--primary);
            border-color: var(--primary);
        }

        .flow-node-action-btn.delete {
            background: white;
            color: var(--gray-500);
            border: 1px solid var(--gray-200);
        }

        .flow-node-action-btn.delete:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .flow-node-action-btn svg {
            width: 14px;
            height: 14px;
        }

        /* Leg Indicator on Connection */
        .flow-leg-indicator {
            position: absolute;
            background: white;
            border: 2px solid var(--gray-200);
            border-radius: 20px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78em;
            font-weight: 600;
            color: var(--gray-700);
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 5;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .flow-leg-indicator:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: scale(1.05);
        }

        .flow-leg-indicator svg {
            width: 16px;
            height: 16px;
            color: var(--primary);
        }

        .flow-leg-indicator .leg-cost {
            color: var(--primary);
            font-weight: 700;
        }

        /* Branch Column */
        .flow-branch-column {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
            position: relative;
        }

        .flow-branch-label {
            position: absolute;
            top: -24px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(72, 140, 154, 0.1);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.72em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        /* Flow Empty State */
        .flow-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 80px 40px;
            text-align: center;
        }

        .flow-empty-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }

        .flow-empty-icon svg {
            color: var(--primary);
            opacity: 0.6;
        }

        .flow-empty-state h4 {
            margin: 0 0 8px;
            font-size: 1.2em;
            font-weight: 600;
            color: var(--dark);
        }

        .flow-empty-state p {
            margin: 0;
            color: var(--gray-500);
            font-size: 0.95em;
        }

        /* ==================== NEW JOURNEY FLOW LAYOUT ==================== */

        .journey-flow-container {
            position: relative;
            background: linear-gradient(135deg, #fafbfc 0%, #f5f7f8 100%);
            border-radius: var(--radius-lg);
            padding: 24px;
            min-height: 400px;
            overflow: visible;
        }

        .journey-flow-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 0.9em;
            color: var(--gray-700);
        }

        .journey-flow-hint svg {
            color: var(--primary);
            flex-shrink: 0;
        }

        .journey-flow-hint strong {
            color: var(--primary);
        }

        .journey-flow-layout {
            display: grid;
            grid-template-columns: minmax(200px, 280px) 1fr minmax(200px, 280px);
            gap: 20px;
            position: relative;
            min-height: 350px;
            overflow: visible;
        }

        .journey-column {
            display: flex;
            flex-direction: column;
        }

        .column-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: white;
            border-radius: var(--radius-md);
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9em;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .column-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .column-header svg {
            color: var(--primary);
        }

        .column-header .column-subtext {
            font-weight: 400;
            color: var(--gray-500);
            font-size: 0.85em;
        }

        .journey-nodes {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Journey Node Card */
        .journey-flow-layout .journey-node {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-md);
            border: 2px solid var(--gray-200);
            position: relative;
            transition: all 0.3s ease;
        }

        .journey-flow-layout .journey-node:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 32px rgba(72, 140, 154, 0.15);
        }

        .journey-flow-layout .journey-node.origin-node {
            border-left: 4px solid var(--primary);
        }

        .journey-flow-layout .journey-node.destination-node {
            border-left: 4px solid var(--success);
        }

        .journey-flow-layout .journey-node.stop-node {
            border-left: 4px solid var(--accent);
        }

        /* Compact stop card */
        .journey-flow-layout .journey-node.stop-compact {
            padding: 12px 14px;
            gap: 6px;
            cursor: pointer;
        }

        .journey-flow-layout .journey-node.stop-compact .journey-node-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }

        .journey-flow-layout .journey-node.stop-compact .journey-node-icon svg {
            width: 16px;
            height: 16px;
        }

        .journey-flow-layout .journey-node.stop-compact .journey-node-title {
            font-size: 0.85em;
        }

        .journey-flow-layout .journey-node.stop-compact .journey-node-address {
            font-size: 0.72em;
            -webkit-line-clamp: 1;
        }

        .journey-node-inventory-compact {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75em;
            color: var(--gray-600);
            font-weight: 600;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
        }

        .journey-node-inventory-compact .inv-sep {
            color: var(--gray-300);
        }

        /* Stop popover */
        .stop-popover {
            position: absolute;
            background: white;
            border-radius: 14px;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18), 0 2px 8px rgba(0, 0, 0, 0.08);
            z-index: 200;
            width: 460px;
            overflow: hidden;
            animation: popoverIn 0.2s ease;
        }

        .stop-popover-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: white;
        }

        .stop-popover-title {
            font-weight: 700;
            font-size: 0.9em;
            letter-spacing: 0.2px;
        }

        .stop-popover-subtitle {
            font-size: 0.72em;
            color: rgba(255,255,255,0.75);
            font-weight: 400;
            margin-top: 2px;
        }

        .stop-popover-close {
            background: rgba(255,255,255,0.15);
            border: none;
            color: rgba(255,255,255,0.8);
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            transition: all 0.15s ease;
        }

        .stop-popover-close:hover {
            color: white;
            background: rgba(255,255,255,0.25);
        }

        .stop-popover-body {
            padding: 14px 18px;
        }

        .stop-popover-top-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 6px;
        }

        .stop-popover-section-title {
            font-size: 0.7em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--gray-400);
            margin: 10px 0 6px 0;
        }

        .stop-popover-section-title:first-child {
            margin-top: 0;
        }

        .stop-popover-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            margin-bottom: 0;
        }

        .stop-popover-stat {
            text-align: center;
            padding: 8px 4px;
            background: var(--gray-50, #f8f9fa);
            border-radius: 8px;
        }

        .stop-popover-stat-value {
            font-size: 1.05em;
            font-weight: 700;
            color: var(--dark);
        }

        .stop-popover-stat-label {
            font-size: 0.65em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .stop-popover-dates {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 0;
        }

        .stop-popover-date-card {
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--gray-200, #e9ecef);
        }

        .stop-popover-date-label {
            font-size: 0.65em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-400);
            margin-bottom: 2px;
        }

        .stop-popover-date-value {
            font-size: 0.82em;
            font-weight: 600;
            color: var(--dark);
        }

        .stop-popover-fee-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }

        .stop-popover-fee-row:not(:last-child) {
            border-bottom: 1px solid var(--gray-100);
        }

        .stop-popover-fee-info {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .stop-popover-fee-name {
            font-size: 0.8em;
            font-weight: 600;
            color: var(--dark);
        }

        .stop-popover-fee-detail {
            font-size: 0.68em;
            color: var(--gray-500);
        }

        .stop-popover-fee-amount {
            font-size: 0.85em;
            font-weight: 700;
            color: var(--primary);
        }

        .stop-popover-fee-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            margin-top: 6px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.08) 0%, rgba(72, 140, 154, 0.04) 100%);
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.85em;
        }

        .stop-popover-fee-total .fee-total-label {
            color: var(--gray-700);
        }

        .stop-popover-fee-total .fee-total-amount {
            color: var(--primary);
        }

        .stop-popover-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
        }

        .stop-popover-row:not(:last-child) {
            border-bottom: 1px solid var(--gray-100);
        }

        .stop-popover-label {
            font-size: 0.8em;
            color: var(--gray-500);
        }

        .stop-popover-value {
            font-size: 0.85em;
            font-weight: 600;
            color: var(--dark);
        }

        .stop-popover-actions {
            display: flex;
            gap: 8px;
            padding: 12px 18px;
            border-top: 1px solid var(--gray-100);
        }

        .stop-popover-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 0.8em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .stop-popover-btn.edit {
            background: var(--gray-100);
            color: var(--dark);
        }

        .stop-popover-btn.edit:hover {
            background: var(--primary);
            color: white;
        }

        .stop-popover-btn.delete {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .stop-popover-btn.delete:hover {
            background: #dc2626;
            color: white;
        }

        .journey-flow-layout .journey-node-header {
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 12px;
            margin-bottom: 0;
        }

        .journey-flow-layout .journey-node-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .journey-flow-layout .journey-node.origin-node .journey-node-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .journey-flow-layout .journey-node.destination-node .journey-node-icon {
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
            color: white;
        }

        .journey-flow-layout .journey-node.stop-node .journey-node-icon {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            color: white;
        }

        .journey-flow-layout .journey-node-info {
            flex: 1;
            min-width: 0;
        }

        .journey-flow-layout .journey-node-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.95em;
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .journey-flow-layout .journey-node-address {
            font-size: 0.8em;
            color: var(--gray-600);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Inventory Display */
        .journey-flow-layout .journey-node-inventory {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
            padding: 14px;
            margin-top: 12px;
        }

        .inventory-stat {
            text-align: center;
        }

        .inventory-stat-value {
            font-size: 1.2em;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }

        .inventory-stat-label {
            font-size: 0.7em;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Connect Button */
        .journey-flow-layout .journey-node-connect {
            position: absolute;
            right: -14px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid white;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 12px rgba(72, 140, 154, 0.4);
            transition: all 0.2s ease;
            z-index: 10;
        }

        .journey-flow-layout .journey-node-connect:hover {
            transform: translateY(-50%) scale(1.15);
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.5);
        }

        .journey-flow-layout .journey-node-connect.connecting {
            animation: pulse-connect 1s infinite;
        }

        @keyframes pulse-connect {
            0%, 100% { box-shadow: 0 0 0 0 rgba(72, 140, 154, 0.6); }
            50% { box-shadow: 0 0 0 10px rgba(72, 140, 154, 0); }
        }

        /* Destination Receive Port (left side) */
        .journey-flow-layout .journey-node-receive {
            position: absolute;
            left: -14px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gray-300);
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
            z-index: 10;
        }

        .journey-flow-layout .journey-node-receive.can-receive {
            background: var(--success);
            animation: pulse-receive 1s infinite;
        }

        @keyframes pulse-receive {
            0%, 100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.6); }
            50% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
        }

        /* Intermediate Stops Column */
        .journey-stops-column {
            flex: 1;
        }

        .journey-stops-wrapper {
            display: flex;
            flex-direction: column;
            gap: 16px;
            flex: 1;
        }

        .journey-stops-scroll {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            gap: 16px;
            overflow: visible;
            padding: 8px 0;
            margin-top: 60px;
        }

        .journey-stops-scroll::-webkit-scrollbar {
            height: 6px;
        }

        .journey-stops-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .journey-stops-scroll::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 3px;
        }

        .journey-stop-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 200px;
            max-width: 220px;
            flex: 0 0 auto;
        }

        .journey-stop-card {
            min-width: 180px;
            max-width: 220px;
            flex-shrink: 0;
            width: 100%;
        }

        .journey-stop-card .journey-node {
            height: 100%;
        }

        /* Add Stop Button */
        .journey-add-stop-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: transparent;
            border: 1px solid var(--gray-300);
            border-radius: 999px;
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 0;
            white-space: nowrap;
            margin-left: auto;
        }

        .journey-add-stop-btn svg {
            width: 14px;
            height: 14px;
        }

        .journey-add-stop-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(72, 140, 154, 0.08);
        }

        /* Empty Stops State */
        .journey-stops-empty {
            flex: 0 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--gray-50);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            text-align: center;
            border: 1px dashed var(--gray-300);
            min-width: 220px;
            max-width: 240px;
        }

        .journey-stops-empty svg {
            color: var(--gray-400);
            margin-bottom: 12px;
        }

        .journey-stops-empty p {
            color: var(--gray-500);
            font-size: 0.9em;
            margin: 0;
        }

        /* Connection Lines SVG */
        .journey-connections-svg {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 5;
            overflow: visible;
        }

        .journey-leg-line {
            fill: none;
            stroke-width: 3;
            stroke: var(--primary);
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .journey-leg-line:hover {
            stroke-width: 4;
            opacity: 1;
        }

        .journey-leg-line-placeholder {
            fill: none;
            stroke-width: 2;
            stroke: #ccc;
            stroke-dasharray: 6 4;
            opacity: 0.5;
            pointer-events: none;
        }

        /* Leg Badge (on the connection) */
        .journey-leg-badge {
            position: absolute;
            background: white;
            border: 2px solid var(--primary);
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.75em;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            z-index: 15;
            transition: all 0.2s ease;
        }

        .journey-leg-badge:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.05);
        }

        .journey-leg-badge svg {
            width: 14px;
            height: 14px;
        }

        /* Leg Popover */
        .leg-popover {
            position: absolute;
            transform: translateX(-50%);
            background: white;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            z-index: 200;
            min-width: 240px;
            overflow: hidden;
            animation: popoverIn 0.2s ease;
        }

        @keyframes popoverIn {
            from { opacity: 0; transform: translateX(-50%) translateY(8px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .leg-popover-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: linear-gradient(135deg, #1a365d 0%, #2d4a7a 100%);
            color: white;
        }

        .leg-popover-title {
            font-weight: 700;
            font-size: 0.85em;
        }

        .leg-popover-close {
            background: none;
            border: none;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            padding: 2px;
        }

        .leg-popover-close:hover {
            color: white;
        }

        .leg-popover-body {
            padding: 12px 16px;
        }

        .leg-popover-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
        }

        .leg-popover-row:not(:last-child) {
            border-bottom: 1px solid var(--gray-100);
        }

        .leg-popover-label {
            font-size: 0.8em;
            color: var(--gray-500);
        }

        .leg-popover-value {
            font-size: 0.85em;
            font-weight: 600;
            color: var(--dark);
        }

        .leg-popover-actions {
            display: flex;
            gap: 8px;
            padding: 10px 16px 14px;
            border-top: 1px solid var(--gray-100);
        }

        .leg-popover-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 0.78em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .leg-popover-btn.edit {
            background: rgba(72, 140, 154, 0.1);
            color: var(--primary);
        }

        .leg-popover-btn.edit:hover {
            background: var(--primary);
            color: white;
        }

        .leg-popover-btn.delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .leg-popover-btn.delete:hover {
            background: #dc3545;
            color: white;
        }

        /* Drag Preview */
        .journey-drag-preview {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 100;
            overflow: visible;
        }

        .journey-drag-preview line {
            display: none;
        }

        .journey-flow-layout.dragging .journey-drag-preview line {
            display: block;
        }

        .journey-flow-layout.dragging .journey-node-receive {
            background: var(--gray-400);
        }

        .journey-flow-layout.dragging .journey-node-receive.can-receive {
            background: var(--success);
        }

        /* Empty State — scoped to .journey-flow-container (which must be position:relative) */
        .journey-empty-state {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: rgba(255, 255, 255, 0.9);
            border-radius: var(--radius-lg);
            z-index: 6;
        }

        .journey-empty-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
        }

        .journey-empty-icon svg {
            color: var(--gray-400);
        }

        .journey-empty-state h4 {
            margin: 0 0 8px;
            color: var(--dark);
            font-size: 1.2em;
        }

        .journey-empty-state p {
            margin: 0;
            color: var(--gray-500);
            font-size: 0.95em;
        }

        .journey-node-placeholder {
            padding: 40px 20px;
            text-align: center;
            color: var(--gray-400);
            font-style: italic;
            background: rgba(0, 0, 0, 0.02);
            border-radius: var(--radius-lg);
            border: 2px dashed var(--gray-200);
        }

        /* Node Actions */
        .journey-node-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-100);
        }

        .journey-node-action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.8em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .journey-node-action-btn.edit-btn {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .journey-node-action-btn.edit-btn:hover {
            background: var(--primary);
            color: white;
        }

        .journey-node-action-btn.delete-btn {
            background: #fee2e2;
            color: #dc2626;
        }

        .journey-node-action-btn.delete-btn:hover {
            background: #dc2626;
            color: white;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .journey-flow-layout {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .journey-node-connect {
                right: auto;
                bottom: -14px;
                top: auto;
                transform: translateX(-50%);
                left: 50%;
            }

            .journey-node-receive {
                left: auto;
                top: -14px;
                transform: translateX(-50%);
                left: 50%;
            }

            .journey-stops-scroll {
                flex-direction: column;
            }

            .journey-stop-card {
                min-width: auto;
                max-width: none;
            }
        }

        /* ==================== FLOW MODALS ==================== */

        .flow-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 20px;
        }

        .flow-modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .flow-modal {
            background: white;
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.25);
            transform: scale(0.9) translateY(20px);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .flow-modal-overlay.active .flow-modal {
            transform: scale(1) translateY(0);
        }

        .flow-modal.flow-modal-wide {
            max-width: 680px;
        }

        .flow-modal.flow-modal-compact {
            max-width: 420px;
        }

        .flow-modal-header {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 24px 28px;
            border-bottom: 1px solid var(--gray-100);
            background: linear-gradient(180deg, white 0%, var(--gray-50) 100%);
        }

        .flow-modal-header-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 6px 20px rgba(224, 127, 58, 0.35);
            flex-shrink: 0;
        }

        .flow-modal-header-icon.leg-icon {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.35);
        }

        .flow-modal-header-icon.add-icon {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
        }

        .flow-modal-header-icon.branch-icon {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.35);
        }

        .flow-modal-header-icon.merge-icon {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.35);
        }

        .flow-modal-header-text {
            flex: 1;
        }

        .flow-modal-header-text h3 {
            margin: 0;
            font-size: 1.15em;
            font-weight: 700;
            color: var(--dark);
        }

        .flow-modal-header-text p {
            margin: 4px 0 0;
            font-size: 0.88em;
            color: var(--gray-500);
        }

        .flow-modal-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: var(--gray-100);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .flow-modal-close:hover {
            background: var(--gray-200);
            color: var(--dark);
        }

        .flow-modal-body {
            padding: 28px;
            overflow-y: auto;
            flex: 1;
        }

        .flow-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 28px;
            border-top: 1px solid var(--gray-100);
            background: var(--gray-50);
        }

        /* Modal Form Fields */
        .modal-form-group {
            margin-bottom: 20px;
        }

        .modal-form-group:last-child {
            margin-bottom: 0;
        }

        .modal-form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85em;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .modal-form-label svg {
            width: 14px;
            height: 14px;
            color: var(--primary);
        }

        .modal-form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
            transition: all 0.2s ease;
            background: white;
            box-sizing: border-box;
        }

        .modal-form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
        }

        .modal-form-input::placeholder {
            color: var(--gray-400);
        }

        .modal-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .modal-form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .modal-input-group {
            display: flex;
            align-items: center;
        }

        .modal-input-group .modal-form-input {
            border-radius: var(--radius-sm) 0 0 var(--radius-sm);
            border-right: none;
        }

        .modal-input-group .modal-input-suffix {
            padding: 12px 14px;
            background: var(--gray-100);
            border: 1.5px solid var(--gray-200);
            border-left: none;
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            font-size: 0.88em;
            color: var(--gray-600);
            white-space: nowrap;
        }

        .modal-select-wrapper {
            position: relative;
        }

        .modal-select-wrapper select {
            appearance: none;
            padding-right: 40px;
        }

        .modal-select-wrapper::after {
            content: '';
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 6px solid var(--gray-400);
            pointer-events: none;
        }

        /* Stop Type Selector */
        .stop-type-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .stop-type-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 12px;
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .stop-type-option:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .stop-type-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.15);
        }

        .stop-type-option .type-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .stop-type-option .type-icon.warehouse {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .stop-type-option .type-icon.port {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .stop-type-option .type-icon.customs {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        }

        .stop-type-option .type-label {
            font-size: 0.82em;
            font-weight: 600;
            color: var(--dark);
        }

        /* Fee Editor in Modal */
        .modal-fees-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px dashed var(--gray-200);
        }

        .modal-fees-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .modal-fees-header h4 {
            margin: 0;
            font-size: 0.95em;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-fees-header h4 svg {
            color: var(--accent);
        }

        .modal-fees-column-headers {
            display: grid;
            grid-template-columns: 1.2fr 90px 110px 110px 36px;
            gap: 12px;
            padding: 0 16px 10px 16px;
            font-size: 0.7em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .modal-fee-item {
            display: grid;
            grid-template-columns: 1.2fr 90px 110px 110px 36px;
            gap: 12px;
            align-items: center;
            padding: 14px 16px;
            background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .modal-fee-item:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.1);
        }

        .modal-fee-item .modal-form-input {
            padding: 10px 12px;
            font-size: 0.9em;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-sm);
            background: white;
            transition: all 0.2s ease;
        }

        .modal-fee-item .modal-form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
            outline: none;
        }

        .modal-fee-item select.modal-form-input {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
        }

        .modal-fee-item:last-child {
            margin-bottom: 0;
        }

        .modal-fee-remove {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: var(--gray-400);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-fee-remove:hover {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .modal-total-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--gray-200);
        }

        .modal-total-label {
            font-size: 0.9em;
            color: var(--gray-600);
        }

        .modal-total-value {
            font-size: 1.15em;
            font-weight: 700;
            color: var(--primary);
        }

        /* ==================== SHIPPING MODAL STYLES ==================== */
        .leg-inventory-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .leg-inventory-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            text-align: center;
        }

        .leg-inv-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .leg-inv-stat-value {
            font-size: 1.8em;
            font-weight: 800;
            color: #1a365d;
            line-height: 1;
        }

        .leg-inv-stat-label {
            font-size: 0.75em;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .leg-form-section {
            margin-bottom: 24px;
        }

        .leg-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 0.85em;
            color: var(--dark);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gray-100);
        }

        .leg-section-title svg {
            color: var(--primary);
        }

        .leg-form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .leg-transport-modes {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .leg-mode-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 10px 8px;
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            background: white;
            cursor: pointer;
            font-size: 0.72em;
            font-weight: 600;
            color: var(--gray-500);
            transition: all 0.2s ease;
        }

        .leg-mode-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(72, 140, 154, 0.05);
        }

        .leg-mode-btn.active {
            border-color: #1a365d;
            background: linear-gradient(135deg, #1a365d 0%, #2d4a7a 100%);
            color: white;
        }

        .leg-rate-group {
            display: flex;
            align-items: stretch;
        }

        .leg-rate-group .modal-form-input:first-child {
            flex: 1;
        }

        .leg-input-prefix {
            display: flex;
            align-items: stretch;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .leg-input-prefix:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
        }

        .leg-prefix {
            display: flex;
            align-items: center;
            padding: 0 10px;
            background: var(--gray-50);
            color: var(--gray-500);
            font-weight: 600;
            font-size: 0.9em;
            border-right: 1px solid var(--gray-200);
        }

        .leg-input-prefix .modal-form-input {
            border: none;
            box-shadow: none;
            border-radius: 0;
        }

        .leg-input-prefix .modal-form-input:focus {
            box-shadow: none;
            border: none;
        }

        .leg-total-display {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #86efac;
            border-radius: 8px;
            font-size: 1.2em;
            font-weight: 800;
            color: #166534;
            height: 44px;
        }

        .leg-tooltip-trigger {
            display: inline-flex;
            align-items: center;
            cursor: help;
            color: var(--gray-400);
            position: relative;
        }

        .leg-tooltip-trigger:hover {
            color: var(--primary);
        }

        .leg-tooltip-trigger:hover::after {
            content: attr(title);
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.78em;
            font-weight: 400;
            white-space: normal;
            width: 220px;
            line-height: 1.4;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            z-index: 1000;
            pointer-events: none;
        }

        .leg-tooltip-trigger:hover::before {
            content: '';
            position: absolute;
            bottom: calc(100% + 2px);
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #1e293b;
            z-index: 1000;
        }

        /* Transport Mode Selector */
        .transport-mode-selector {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 24px;
        }

        .transport-mode-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 14px 8px;
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .transport-mode-option:hover {
            border-color: var(--primary);
        }

        .transport-mode-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .transport-mode-option svg {
            width: 24px;
            height: 24px;
            color: var(--gray-600);
            transition: color 0.2s ease;
        }

        .transport-mode-option.selected svg {
            color: var(--primary);
        }

        .transport-mode-option .mode-label {
            font-size: 0.75em;
            font-weight: 600;
            color: var(--gray-600);
        }

        .transport-mode-option.selected .mode-label {
            color: var(--primary);
        }

        /* Add Stop Options */
        .add-stop-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .add-stop-option {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 18px 20px;
            background: var(--gray-50);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
            width: 100%;
        }

        .add-stop-option:hover {
            border-color: var(--primary);
            background: white;
            transform: translateX(4px);
        }

        .add-stop-option-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .add-stop-option-icon.warehouse {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .add-stop-option-icon.branch {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        }

        .add-stop-option-text {
            flex: 1;
        }

        .add-stop-option-text strong {
            display: block;
            font-size: 0.95em;
            color: var(--dark);
            margin-bottom: 2px;
        }

        .add-stop-option-text span {
            font-size: 0.82em;
            color: var(--gray-500);
        }

        .add-stop-option-arrow {
            color: var(--gray-400);
            transition: all 0.2s ease;
        }

        .add-stop-option:hover .add-stop-option-arrow {
            color: var(--primary);
            transform: translateX(4px);
        }

        /* Branch Configuration */
        .branch-split-info {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.08) 0%, rgba(139, 92, 246, 0.03) 100%);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .branch-total-trucks {
            text-align: center;
        }

        .branch-total-trucks .label {
            display: block;
            font-size: 0.78em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-600);
            margin-bottom: 4px;
        }

        .branch-total-trucks .value {
            font-size: 1.8em;
            font-weight: 700;
            color: #8b5cf6;
        }

        .branch-config-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
        }

        .branch-config-item {
            display: grid;
            grid-template-columns: 1fr 100px 36px;
            gap: 12px;
            align-items: center;
            padding: 16px;
            background: var(--gray-50);
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-200);
        }

        .branch-config-item .branch-name-input {
            font-size: 0.95em;
        }

        .branch-config-item .branch-trucks-input {
            text-align: center;
            font-weight: 600;
        }

        .branch-remove-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: var(--gray-400);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .branch-remove-btn:hover {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .add-branch-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 2px dashed var(--gray-300);
            border-radius: var(--radius-sm);
            color: var(--gray-500);
            font-size: 0.9em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Poppins', sans-serif;
        }

        .add-branch-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .branch-allocation-bar {
            height: 8px;
            background: var(--gray-200);
            border-radius: 4px;
            margin: 20px 0 10px;
            overflow: hidden;
        }

        .branch-allocation-fill {
            height: 100%;
            background: linear-gradient(90deg, #8b5cf6 0%, #06b6d4 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .branch-allocation-status {
            display: flex;
            justify-content: space-between;
            font-size: 0.82em;
        }

        .branch-allocation-status .allocated {
            color: #8b5cf6;
            font-weight: 600;
        }

        .branch-allocation-status .remaining {
            color: var(--gray-500);
        }

        /* Merge Branches List */
        .merge-branches-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .merge-branch-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            background: var(--gray-50);
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-200);
        }

        .merge-branch-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.82em;
            font-weight: 700;
        }

        .merge-branch-icon.branch-a {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .merge-branch-icon.branch-b {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .merge-branch-info {
            flex: 1;
        }

        .merge-branch-info strong {
            display: block;
            font-size: 0.9em;
            color: var(--dark);
        }

        .merge-branch-info span {
            font-size: 0.8em;
            color: var(--gray-500);
        }

        .merge-branch-trucks {
            font-weight: 700;
            color: var(--primary);
            font-size: 0.9em;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .flow-canvas-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .flow-canvas-stats {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .flow-level {
                flex-direction: column;
                gap: 0;
            }

            .flow-node-card {
                min-width: 160px;
            }

            .flow-modal {
                max-width: 95%;
            }

            .modal-form-row,
            .modal-form-row-3 {
                grid-template-columns: 1fr;
            }

            .stop-type-selector {
                grid-template-columns: 1fr;
            }

            .transport-mode-selector {
                grid-template-columns: repeat(2, 1fr);
            }

            .branch-config-item {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .branch-config-item .branch-trucks-input {
                max-width: 100px;
            }
        }

    </style>
