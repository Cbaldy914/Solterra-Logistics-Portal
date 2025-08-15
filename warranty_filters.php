<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

function getWarrantyFiltersFromRequest(): array {
    // Read from GET first; if the page used POST (e.g., via DataTables or forms), fall back to POST
    $src = !empty($_GET) ? $_GET : $_POST;
    $filters = [
        'project_id' => isset($src['project_id']) ? (int)$src['project_id'] : 0,
        'issue_types' => isset($src['issue_types']) ? (array)$src['issue_types'] : [],
        'responsible_party' => isset($src['responsible_party']) ? (string)$src['responsible_party'] : '',
        'statuses' => isset($src['statuses']) ? (array)$src['statuses'] : [],
        'date_from' => isset($src['date_from']) ? (string)$src['date_from'] : '',
        'date_to' => isset($src['date_to']) ? (string)$src['date_to'] : '',
        'hide_closed' => isset($src['hide_closed']) ? (int)$src['hide_closed'] : 0,
        'search' => isset($src['search']) ? (string)$src['search'] : '',
    ];
    return $filters;
}

function persistWarrantyFilters(array $filters): void {
    $_SESSION['warranty_filters'] = $filters;
}

function loadPersistedWarrantyFilters(): array {
    return $_SESSION['warranty_filters'] ?? [
        'project_id' => 0,
        'issue_types' => [],
        'responsible_party' => '',
        'statuses' => [],
        'date_from' => '',
        'date_to' => '',
        'hide_closed' => 0,
        'search' => '',
    ];
}

?>


