<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

function getWarrantyFiltersFromRequest(): array {
    $filters = [
        'project_id' => isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0,
        'issue_types' => isset($_GET['issue_types']) ? (array)$_GET['issue_types'] : [],
        'responsible_party' => isset($_GET['responsible_party']) ? (string)$_GET['responsible_party'] : '',
        'statuses' => isset($_GET['statuses']) ? (array)$_GET['statuses'] : [],
        'date_from' => isset($_GET['date_from']) ? (string)$_GET['date_from'] : '',
        'date_to' => isset($_GET['date_to']) ? (string)$_GET['date_to'] : '',
        'hide_closed' => isset($_GET['hide_closed']) ? (int)$_GET['hide_closed'] : 1,
        'search' => isset($_GET['search']) ? (string)$_GET['search'] : '',
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
        'hide_closed' => 1,
        'search' => '',
    ];
}

?>


