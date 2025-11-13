<?php
// Start session
session_name("logistics_session");
session_start();

// Check if user is logged in and is global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized.php");
    exit();
}

require_once 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Attempts - Solterra Solutions</title>
    <link rel="stylesheet" href="portal.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .attempts-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: #293E4C;
            font-weight: 700;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #488C9A;
        }

        .stat-card.danger {
            border-left-color: #ef4444;
        }

        .stat-card.success {
            border-left-color: #10b981;
        }

        .stat-card.warning {
            border-left-color: #f59e0b;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #293E4C;
        }

        .filters-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .filters-header h2 {
            font-size: 1.25rem;
            color: #293E4C;
            font-weight: 600;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.625rem 0.875rem;
            font-size: 0.95rem;
            border: 2px solid #e5e8eb;
            border-radius: 8px;
            background: #ffffff;
            color: #1f2937;
            transition: all 0.2s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.625rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #6BB8C7 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #1f2937;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
        }

        .btn-export {
            background: #10b981;
            color: white;
        }

        .btn-export:hover {
            background: #059669;
        }

        .table-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 1.125rem;
            color: #293E4C;
            font-weight: 600;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f9fafb;
        }

        thead th {
            padding: 1rem 1.5rem;
            text-align: left;
            font-size: 0.875rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        tbody td {
            padding: 1rem 1.5rem;
            font-size: 0.95rem;
            color: #1f2937;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 600;
            border-radius: 9999px;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .loading {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            opacity: 0.5;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .pagination button {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: 1px solid #e5e7eb;
            background: white;
            color: #1f2937;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .pagination button:hover:not(:disabled) {
            background: #f9fafb;
            border-color: #488C9A;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination .page-info {
            margin: 0 1rem;
            font-size: 0.875rem;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .attempts-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .table-container {
                font-size: 0.875rem;
            }

            thead th,
            tbody td {
                padding: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="attempts-container">
        <div class="page-header">
            <h1>🔒 Login Attempts Monitor</h1>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-label">Total Attempts Today</div>
                <div class="stat-value" id="totalToday">-</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Successful Logins</div>
                <div class="stat-value" id="successToday">-</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Failed Attempts</div>
                <div class="stat-value" id="failedToday">-</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">Unique Users Attempted</div>
                <div class="stat-value" id="uniqueUsers">-</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-header">
                <h2>Filter Login Attempts</h2>
            </div>
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="filterUsername">Username</label>
                    <input type="text" id="filterUsername" placeholder="Search by username">
                </div>
                <div class="filter-group">
                    <label for="filterStatus">Status</label>
                    <select id="filterStatus">
                        <option value="">All Statuses</option>
                        <option value="success">Successful</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filterDateFrom">From Date</label>
                    <input type="date" id="filterDateFrom">
                </div>
                <div class="filter-group">
                    <label for="filterDateTo">To Date</label>
                    <input type="date" id="filterDateTo">
                </div>
                <div class="filter-group">
                    <label for="filterIp">IP Address</label>
                    <input type="text" id="filterIp" placeholder="Search by IP">
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn btn-secondary" onclick="resetFilters()">Clear Filters</button>
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
                <button class="btn btn-export" onclick="exportToCSV()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                        <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
                    </svg>
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Login Attempts Table -->
        <div class="table-card">
            <div class="table-header">
                <h3>Login Attempts</h3>
                <span id="recordCount">Loading...</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>IP Address</th>
                            <th>Failure Reason</th>
                            <th>User Agent</th>
                        </tr>
                    </thead>
                    <tbody id="attemptsTableBody">
                        <tr>
                            <td colspan="6" class="loading">Loading login attempts...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="pagination" style="display: none;">
                <button onclick="changePage(-1)" id="prevBtn">Previous</button>
                <span class="page-info" id="pageInfo">Page 1</span>
                <button onclick="changePage(1)" id="nextBtn">Next</button>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        let totalPages = 1;
        const recordsPerPage = 50;
        let currentFilters = {};

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadStatistics();
            loadAttempts();
            
            // Set default date to today
            document.getElementById('filterDateTo').valueAsDate = new Date();
        });

        async function loadStatistics() {
            try {
                const response = await fetch('get_login_attempts.php?action=stats');
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('totalToday').textContent = data.stats.total_today;
                    document.getElementById('successToday').textContent = data.stats.success_today;
                    document.getElementById('failedToday').textContent = data.stats.failed_today;
                    document.getElementById('uniqueUsers').textContent = data.stats.unique_users;
                }
            } catch (error) {
                console.error('Error loading statistics:', error);
            }
        }

        async function loadAttempts(page = 1) {
            const tbody = document.getElementById('attemptsTableBody');
            tbody.innerHTML = '<tr><td colspan="6" class="loading">Loading...</td></tr>';
            
            try {
                const params = new URLSearchParams({
                    action: 'list',
                    page: page,
                    limit: recordsPerPage,
                    ...currentFilters
                });
                
                const response = await fetch('get_login_attempts.php?' + params);
                const data = await response.json();
                
                if (data.success) {
                    currentPage = page;
                    totalPages = data.pagination.total_pages;
                    
                    document.getElementById('recordCount').textContent = 
                        `Showing ${data.pagination.showing_from}-${data.pagination.showing_to} of ${data.pagination.total_records} records`;
                    
                    if (data.attempts.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                                        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
                                    </svg>
                                    <p>No login attempts found</p>
                                </td>
                            </tr>
                        `;
                    } else {
                        tbody.innerHTML = data.attempts.map(attempt => `
                            <tr>
                                <td>${new Date(attempt.attempt_time).toLocaleString()}</td>
                                <td><strong>${escapeHtml(attempt.username)}</strong></td>
                                <td>
                                    <span class="badge ${attempt.success ? 'badge-success' : 'badge-danger'}">
                                        ${attempt.success ? '✓ Success' : '✗ Failed'}
                                    </span>
                                </td>
                                <td>${escapeHtml(attempt.ip_address)}</td>
                                <td>${attempt.failure_reason ? escapeHtml(attempt.failure_reason).replace('_', ' ') : '-'}</td>
                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                    title="${escapeHtml(attempt.user_agent || 'N/A')}">
                                    ${escapeHtml(attempt.user_agent || 'N/A')}
                                </td>
                            </tr>
                        `).join('');
                    }
                    
                    // Update pagination
                    document.getElementById('pagination').style.display = totalPages > 1 ? 'flex' : 'none';
                    document.getElementById('pageInfo').textContent = `Page ${currentPage} of ${totalPages}`;
                    document.getElementById('prevBtn').disabled = currentPage === 1;
                    document.getElementById('nextBtn').disabled = currentPage === totalPages;
                } else {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" class="empty-state">
                                <p>Error loading attempts: ${escapeHtml(data.message)}</p>
                            </td>
                        </tr>
                    `;
                }
            } catch (error) {
                console.error('Error loading attempts:', error);
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="empty-state">
                            <p>Error loading data. Please try again.</p>
                        </td>
                    </tr>
                `;
            }
        }

        function applyFilters() {
            currentFilters = {};
            
            const username = document.getElementById('filterUsername').value.trim();
            if (username) currentFilters.username = username;
            
            const status = document.getElementById('filterStatus').value;
            if (status) currentFilters.status = status;
            
            const dateFrom = document.getElementById('filterDateFrom').value;
            if (dateFrom) currentFilters.date_from = dateFrom;
            
            const dateTo = document.getElementById('filterDateTo').value;
            if (dateTo) currentFilters.date_to = dateTo;
            
            const ip = document.getElementById('filterIp').value.trim();
            if (ip) currentFilters.ip = ip;
            
            loadAttempts(1);
            loadStatistics();
        }

        function resetFilters() {
            document.getElementById('filterUsername').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').valueAsDate = new Date();
            document.getElementById('filterIp').value = '';
            currentFilters = {};
            loadAttempts(1);
            loadStatistics();
        }

        function changePage(delta) {
            const newPage = currentPage + delta;
            if (newPage >= 1 && newPage <= totalPages) {
                loadAttempts(newPage);
            }
        }

        async function exportToCSV() {
            try {
                const params = new URLSearchParams({
                    action: 'export',
                    ...currentFilters
                });
                
                window.location.href = 'get_login_attempts.php?' + params;
            } catch (error) {
                console.error('Error exporting CSV:', error);
                alert('Failed to export CSV. Please try again.');
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

