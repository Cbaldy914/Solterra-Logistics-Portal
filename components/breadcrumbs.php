<?php
/**
 * Standardized Breadcrumbs Helper
 * Usage:
 *   require_once 'components/breadcrumbs.php';
 *   echo slp_render_breadcrumbs(['current_label' => 'Warehouse Inventory']);
 *
 * Behavior:
 * - Always starts with Dashboard.
 * - If a valid project_id is present, adds the project name crumb linking to project_overview.
 * - Otherwise, best-effort context via HTTP referer (e.g., Manage Warehouses, Modules, Manage Projects).
 * - Ends with the current page label (no link).
 *
 * Options:
 * - current_label (string): Override for the current page label.
 * - conn (mysqli|null): Optional DB connection. If omitted and project_id is present, attempts to open/close a temp connection.
 * - class (string): CSS class for the container. Default 'breadcrumb'.
 * - separator (string): Text separator between crumbs. Default '»'.
 * - extra (array): Additional crumbs to insert after Dashboard and before context/current. Each: ['label'=>..., 'url'=>...].
 * - omit_current (bool): If true, do not render the final current page crumb.
 * - ref_map (array): Map of substring => ['label'=>..., 'url'=>...]; used to interpret HTTP_REFERER.
 */

if (!function_exists('slp_render_breadcrumbs')) {
    function slp_render_breadcrumbs(array $opts = []) {
        $class     = $opts['class']     ?? 'breadcrumb';
        $separator = $opts['separator'] ?? '»';
        // Note: we intentionally avoid using a passed mysqli connection because some pages may close it early.
        // For robustness, this helper opens a short-lived connection only when project lookup is needed.
        $current   = $opts['current_label'] ?? null;
        $extra     = is_array($opts['extra'] ?? null) ? $opts['extra'] : [];

        // Default ref map for common hubs
        $default_ref_map = [
            // With extension
            'manage_warehouses.php' => ['label' => 'Manage Warehouses', 'url' => 'manage_warehouses.php'],
            'manage_projects.php'   => ['label' => 'Manage Projects',   'url' => 'manage_projects.php'],
            'modules.php'           => ['label' => 'Modules',           'url' => 'modules.php'],
            'manage_deliveries.php' => ['label' => 'Manage Deliveries', 'url' => 'manage_deliveries.php'],
            // Without extension (some menus link this way)
            'manage_warehouses'     => ['label' => 'Manage Warehouses', 'url' => 'manage_warehouses.php'],
            'manage_projects'       => ['label' => 'Manage Projects',   'url' => 'manage_projects.php'],
            'modules'               => ['label' => 'Modules',           'url' => 'modules.php'],
            'manage_deliveries'     => ['label' => 'Manage Deliveries', 'url' => 'manage_deliveries.php'],
        ];
        $ref_map = $opts['ref_map'] ?? $default_ref_map;

        // Infer current label from script name if not provided
        if ($current === null) {
            $script = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
            $labels = [
                'project_overview.php'        => 'Project Overview',
                'manage_warehouse_inventory.php' => 'Warehouse Inventory',
                'manage_warehouses.php'       => 'Manage Warehouses',
                'modules.php'                 => 'Modules',
                'add_module_batch.php'        => 'Add Module Batch',
                'edit_module_batch.php'       => 'Edit Module Batch',
                'manage_projects.php'         => 'Manage Projects',
            ];
            $current = $labels[$script] ?? ucwords(str_replace(['_', '.php'], [' ', ''], $script));
        }

        $crumbs = [];
        $crumbs[] = ['label' => 'Dashboard', 'url' => 'dashboard.php'];

        // Determine project context: prefer override in opts, fallback to GET
        $project_id = isset($opts['project_id']) ? (int)$opts['project_id'] : (int)($_GET['project_id'] ?? 0);
        $project_name = null;
        if ($project_id > 0) {
            $project_name = 'Project';
            if (!function_exists('getDBConnection')) {
                @require_once __DIR__ . '/../config.php';
            }
            if (function_exists('getDBConnection')) {
                $tmp = @getDBConnection();
                if ($tmp) {
                    $stmt = @$tmp->prepare('SELECT project_name FROM projects WHERE id = ?');
                    if ($stmt) {
                        @$stmt->bind_param('i', $project_id);
                        @$stmt->execute();
                        @$stmt->bind_result($pn);
                        if (@$stmt->fetch()) { $project_name = $pn; }
                        @$stmt->close();
                    }
                    @$tmp->close();
                }
            }
            // Always place project crumb immediately after Dashboard
            $crumbs[] = ['label' => $project_name, 'url' => 'project_overview.php?project_id=' . (int)$project_id];
        }

        // Insert any extra crumbs provided by caller, but avoid duplicating the project overview link
        $filtered_extra = [];
        foreach ($extra as $c) {
            if (!empty($c['url']) && $project_id > 0) {
                $url = (string)$c['url'];
                // Normalize for comparison
                if (stripos($url, 'project_overview.php') !== false && stripos($url, 'project_id=') !== false) {
                    // Skip: project crumb already added with project name
                    continue;
                }
            }
            if (!empty($c['label'])) {
                $filtered_extra[] = ['label' => (string)$c['label'], 'url' => $c['url'] ?? null];
            }
        }
        foreach ($filtered_extra as $c) { $crumbs[] = $c; }

        // If no explicit project context and no extras, best-effort referer-based crumb
        if ($project_id <= 0) {
            $ref = $_SERVER['HTTP_REFERER'] ?? '';
            if ($ref) {
                $path = parse_url($ref, PHP_URL_PATH) ?: '';
                $refBase = basename($path);
                if (isset($ref_map[$refBase])) {
                    $crumbs[] = $ref_map[$refBase];
                } else {
                    foreach ($ref_map as $needle => $meta) {
                        if (stripos($ref, $needle) !== false) { $crumbs[] = $meta; break; }
                    }
                }
            }
        }

        // Current page crumb (no link) unless explicitly omitted
        // Default behavior: if we're on project_overview for a specific project, omit the redundant "Project Overview" crumb
        if (array_key_exists('omit_current', $opts)) {
            $omit_current = (bool)$opts['omit_current'];
        } else {
            $omit_current = false;
            $script = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
            $is_project_overview = in_array($script, ['project_overview.php','project_overview'], true);
            $label_is_overview   = (stripos($current ?? '', 'Project Overview') !== false);
            if (($project_id ?? 0) > 0 && ($is_project_overview || $label_is_overview)) {
                $omit_current = true;
            }
        }
        if (!$omit_current) {
            $crumbs[] = ['label' => $current, 'url' => null];
        }

        // Replace any crumb that links to project_overview with the project name (if known)
        if ($project_id > 0 && $project_name !== null) {
            foreach ($crumbs as &$c) {
                if (!empty($c['url'])) {
                    $u = (string)$c['url'];
                    if (stripos($u, 'project_overview.php') !== false && stripos($u, 'project_id=') !== false) {
                        $c['label'] = $project_name;
                    }
                }
            }
            unset($c);
        }

        // Deduplicate consecutive identical crumbs (e.g., when both extra and referer add the same hub)
        $deduped = [];
        foreach ($crumbs as $c) {
            $last = end($deduped);
            if ($last && (($last['label'] ?? '') === ($c['label'] ?? '')) && (($last['url'] ?? null) === ($c['url'] ?? null))) {
                continue;
            }
            $deduped[] = $c;
        }
        $crumbs = $deduped;

        // Render HTML
        $html = '<div class="' . htmlspecialchars($class) . '" style="margin: 10px 20px;">';
        $first = true;
        foreach ($crumbs as $c) {
            if (!$first) {
                $html .= '<span class="separator" style="margin: 0 8px; color: #6c757d;">' . htmlspecialchars($separator) . '</span>';
            }
            $first = false;
            $label = htmlspecialchars((string)$c['label']);
            if (!empty($c['url'])) {
                $url = htmlspecialchars((string)$c['url']);
                $html .= '<a href="' . $url . '" style="color: #488C9A; text-decoration: none;">' . $label . '</a>';
            } else {
                $html .= '<span>' . $label . '</span>';
            }
        }
        $html .= '</div>';
        return $html;
    }
}

?>
