<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'global_admin' && $role !== 'admin') {
    header("Location: unauthorized");
    exit();
}

$estimate_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($estimate_id <= 0) {
    die("Estimate ID not specified.");
}

require_once '../config.php';
require_once 'notification_helpers.php';
require_once 'document_helpers.php';

// Notification toggles
$notify_user_on_rate_update = true;
$notify_account_admins_on_rate_update = false;
$notify_global_admins_on_rate_update = false;

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$currentUserId = (int)$_SESSION['user_id'];
$adminAccounts = $role === 'admin' ? account_ids_for_user($currentUserId) : [];

// Fetch estimate data with account info
$stmt = $conn->prepare("
    SELECT 
        wq.user_id,
        wq.name,
        wq.estimate_data,
        wq.created_at,
        cau.account_id,
        ca.name AS account_name,
        u.username
    FROM warehouse_quotes wq
    LEFT JOIN (
        SELECT user_id, MIN(account_id) AS account_id
        FROM customer_account_users
        GROUP BY user_id
    ) AS cau ON wq.user_id = cau.user_id
    LEFT JOIN customer_accounts ca ON ca.id = cau.account_id
    LEFT JOIN users u ON u.id = wq.user_id
    WHERE wq.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $estimate_id);
$stmt->execute();
$result = $stmt->get_result();
$estimateRow = $result->fetch_assoc();
$stmt->close();

if (!$estimateRow) {
    $conn->close();
    die("Estimate not found.");
}

$estimate_data = json_decode($estimateRow['estimate_data'], true) ?? [];

// Fetch warehouses for dropdown
$warehouses = [];
$wh_stmt = $conn->prepare("SELECT id, name FROM warehouses ORDER BY name ASC");
$wh_stmt->execute();
$wh_result = $wh_stmt->get_result();
while ($wh_row = $wh_result->fetch_assoc()) {
    $warehouses[] = $wh_row;
}
$wh_stmt->close();

// Admin scoping
if ($role === 'admin') {
    $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
    if (empty(array_intersect($adminAccounts, $ownerAccounts))) {
        $conn->close();
        header("Location: unauthorized");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_quote'])) {
        $warehouse_location = trim($_POST['warehouse_location'] ?? '');
        $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
        $in_fee = floatval($_POST['in_fee'] ?? 0);
        $out_fee = floatval($_POST['out_fee'] ?? 0);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee'] ?? 0);

        // Handle multiple document uploads
        $uploaded_doc_ids = [];
        if (!empty($_FILES['quote_documents']['name'][0])) {
            $file_count = count($_FILES['quote_documents']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['quote_documents']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_data = [
                        'name' => $_FILES['quote_documents']['name'][$i],
                        'type' => $_FILES['quote_documents']['type'][$i],
                        'tmp_name' => $_FILES['quote_documents']['tmp_name'][$i],
                        'error' => $_FILES['quote_documents']['error'][$i],
                        'size' => $_FILES['quote_documents']['size'][$i]
                    ];

                    try {
                        // Process the uploaded file
                        $processed_file = processDocumentUpload($file_data, 'warehousing');
                        
                        // Save to project_documents table
                        $document_data = [
                            'project_id' => null,  // No project association
                            'warehouse_id' => $warehouse_id > 0 ? $warehouse_id : null,
                            'document_type' => 'warehousing',
                            'document_sub_type' => 'Quote',
                            'original_name' => $processed_file['original_name'],
                            'file_size' => $processed_file['size'],
                            'mime_type' => $processed_file['mime_type'],
                            'uploaded_by' => $currentUserId,
                            'tmp_name' => $processed_file['tmp_name'],
                            'description' => "Warehouse quote for {$warehouse_location}",
                            'entity_context' => json_encode(['warehouse_quote_id' => $estimate_id, 'warehouse_location' => $warehouse_location])
                        ];

                        $save_result = saveDocumentToProjectDocuments($conn, $document_data);
                        if ($save_result && isset($save_result['document_id'])) {
                            $uploaded_doc_ids[] = $save_result['document_id'];
                        }
                    } catch (Exception $e) {
                        error_log("Failed to upload document: " . $e->getMessage());
                    }
                }
            }
        }

        if (empty($warehouse_location) || $warehouse_id <= 0 || $in_fee < 0 || $out_fee < 0 || $monthly_storage_fee < 0 || empty($uploaded_doc_ids)) {
            $error_message = "Please fill in all required fields with valid values and attach at least one document.";
        } else {
            $new_quote = [
                'warehouse_location' => $warehouse_location,
                'warehouse_id' => $warehouse_id,
                'in_fee_per_pallet' => $in_fee,
                'out_fee_per_pallet' => $out_fee,
                'monthly_storage_cost_per_pallet' => $monthly_storage_fee,
                'document_ids' => $uploaded_doc_ids  // Store IDs instead of paths
            ];
            if (!isset($estimate_data['quotes'])) {
                $estimate_data['quotes'] = [];
            }
            $estimate_data['quotes'][] = $new_quote;

            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote added successfully with " . count($uploaded_doc_ids) . " document(s)!";

                $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
                $title = "Warehouse rate added: " . ($estimateRow['name'] ?? 'Estimate');
                $message = "Rates were added by " . ($_SESSION['username'] ?? 'an admin') . " for " . ($estimate_data['project_location'] ?? 'location') . ".";
                $link = 'view_warehouse_estimate?id=' . $estimate_id;

                if ($notify_user_on_rate_update) {
                    notify_user((int)$estimateRow['user_id'], 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_account_admins_on_rate_update) {
                    notify_account_admins($ownerAccounts, 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_global_admins_on_rate_update) {
                    notify_global_admins('warehouse_estimate_rated', $title, $message, $link);
                }
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        }
    } elseif (isset($_POST['delete_quote'])) {
        $quote_index = intval($_POST['quote_index'] ?? -1);
        if (isset($estimate_data['quotes'][$quote_index])) {
            array_splice($estimate_data['quotes'], $quote_index, 1);
            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote deleted successfully!";
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        } else {
            $error_message = "Quote not found.";
        }
    }
}

// Fetch documents for display
$quote_documents = [];
if (!empty($estimate_data['quotes'])) {
    foreach ($estimate_data['quotes'] as $idx => $quote) {
        if (!empty($quote['document_ids'])) {
            $placeholders = implode(',', array_fill(0, count($quote['document_ids']), '?'));
            $doc_stmt = $conn->prepare("SELECT id, original_file_name, file_size FROM project_documents WHERE id IN ($placeholders) AND is_active = 1");
            $types = str_repeat('i', count($quote['document_ids']));
            $doc_stmt->bind_param($types, ...$quote['document_ids']);
            $doc_stmt->execute();
            $doc_result = $doc_stmt->get_result();
            $quote_documents[$idx] = [];
            while ($doc_row = $doc_result->fetch_assoc()) {
                $quote_documents[$idx][] = $doc_row;
            }
            $doc_stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Warehouse Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        label { display: block; margin-top: 15px; font-weight: bold; }
        input { width: 95%; padding: 8px; margin-top: 5px; }
        button { background-color: #488C9A; color: white; padding: 10px 20px; margin: 10px 0; border: none; border-radius: 4px; font-size: 1em; cursor: pointer; font-weight: bold; }
        button:hover { background-color: #293E4C; }
        .success-message { color: #0f5132; background: #d1e7dd; border: 1px solid #badbcc; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        .error-message { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        h1, h2 { margin-top: 20px; }
        .admin-hero { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 24px; margin-bottom: 18px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06); border: 1px solid rgba(72, 140, 154, 0.08); position: relative; overflow: hidden; }
        .admin-hero::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .admin-hero__content { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-sub { color: #556; margin: 4px 0 0; }

        /* File Upload Zone */
        .file-upload-zone {
            border: 2px dashed #d1d5db;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f9fafb;
        }
        .file-upload-zone:hover {
            border-color: #488C9A;
            background: #f0f9fb;
        }
        .file-upload-zone.dragover {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .selected-files-list {
            margin-top: 16px;
        }
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .file-icon {
            color: #488C9A;
            font-size: 1.2em;
        }
        .remove-file-btn {
            background: #ef4444;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85em;
            transition: all 0.2s ease;
        }
        .remove-file-btn:hover {
            background: #dc2626;
        }

        /* Documents Modal */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 32px;
            border-radius: 20px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            position: relative;
        }
        .close-modal {
            position: absolute;
            right: 20px;
            top: 20px;
            font-size: 28px;
            font-weight: bold;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .close-modal:hover {
            color: #ef4444;
        }
        .modal-content h2 {
            margin-top: 0;
            color: #293E4C;
        }
        .modal-document-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 1px solid rgba(72, 140, 154, 0.15);
            border-radius: 12px;
            margin-bottom: 12px;
        }
        .modal-doc-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .modal-doc-icon {
            font-size: 1.5em;
            color: #488C9A;
        }
        .modal-doc-name {
            font-weight: 600;
            color: #293E4C;
        }
        .modal-doc-size {
            font-size: 0.85em;
            color: #6c757d;
        }
        .modal-doc-download {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }
        .modal-doc-download:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
        }
        .view-documents-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        .view-documents-link:hover {
            color: #293E4C;
        }
    </style>
</head>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(getGoogleMapsApiKey()); ?>&libraries=places"></script>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehouse Estimate']); ?>
    <section class="admin-hero">
        <div class="admin-hero__content">
            <div>
                <h1>Warehouse Estimate</h1>
                <p class="hero-sub"><?php echo htmlspecialchars($estimateRow['name'] ?? ''); ?></p>
            </div>
        </div>
    </section>

    <?php
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <ul>
        <li><strong>Customer:</strong> <?php echo htmlspecialchars($estimateRow['account_name'] ?? 'Unassigned'); ?></li>
        <li><strong>Created At:</strong> <?php echo htmlspecialchars($estimateRow['created_at'] ?? ''); ?></li>
    </ul>

    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Project Location:</strong> <?php echo htmlspecialchars($estimate_data['project_location'] ?? ''); ?></li>
        <li><strong>Estimated Storage Start:</strong> <?php echo htmlspecialchars($estimate_data['estimated_storage_start'] ?? ''); ?></li>
        <li><strong>Estimated Number of Pallets:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets'] ?? ''); ?></li>
        <li><strong>Pallet Dimensions:</strong> <?php echo htmlspecialchars($estimate_data['pallet_length'] ?? '') . ' x ' . htmlspecialchars($estimate_data['pallet_width'] ?? '') . ' ' . strtolower($estimate_data['pallet_unit'] ?? 'in'); ?></li>
        <li><strong>Stackable:</strong> <?php echo !empty($estimate_data['stackable']) ? 'Yes' : 'No'; ?></li>
        <li><strong>Calculated Square Feet:</strong> <?php echo number_format($estimate_data['square_feet'] ?? 0, 2); ?> sq ft</li>
    </ul>

    <h2>Add Warehouse Rate</h2>
    <form method="POST" action="" enctype="multipart/form-data" id="addQuoteForm">
        <input type="hidden" name="add_quote" value="1">

        <label for="warehouse_id">Warehouse <span style="color: red;">*</span></label>
        <select id="warehouse_id" name="warehouse_id" required>
            <option value="">Select warehouse...</option>
            <?php foreach ($warehouses as $wh): ?>
                <option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="warehouse_location">Warehouse Location (City, State) <span style="color: red;">*</span></label>
        <input type="text" id="warehouse_location" name="warehouse_location" required placeholder="e.g., Phoenix, AZ">

        <label for="in_fee">In Fee (per pallet) <span style="color: red;">*</span></label>
        <input type="number" step="0.01" name="in_fee" required placeholder="0.00">

        <label for="out_fee">Out Fee (per pallet) <span style="color: red;">*</span></label>
        <input type="number" step="0.01" name="out_fee" required placeholder="0.00">

        <label for="monthly_storage_fee">Monthly Storage Fee (per pallet) <span style="color: red;">*</span></label>
        <input type="number" step="0.01" name="monthly_storage_fee" required placeholder="0.00">

        <label for="quote_documents">Quote Documents (PDF/Image/DOC) <span style="color: red;">*</span></label>
        <div class="file-upload-zone" id="fileUploadZone">
            <i class="fas fa-cloud-upload-alt" style="font-size: 3em; color: #9ca3af; margin-bottom: 16px;"></i>
            <p style="margin: 0 0 8px; font-weight: 600; color: #488C9A;">Drop files here or click to browse</p>
            <p style="margin: 0; font-size: 0.85em; color: #9ca3af;">Supports: PDF, DOC, DOCX, JPG, PNG (Max: 50MB each)</p>
        </div>
        <input type="file" id="quote_documents" name="quote_documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple required style="display: none;">
        <div id="selectedFiles" class="selected-files-list"></div>

        <button type="submit">Add Rate</button>
    </form>

    <h2>Existing Rates</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>In Fee (per pallet)</th>
                <th>Out Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
                <th>Documents</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $index => $quote): 
                $doc_count = !empty($quote['document_ids']) ? count($quote['document_ids']) : (!empty($quote['document']) ? 1 : 0);
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['in_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['out_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                    <td>
                        <?php if ($doc_count > 0): ?>
                            <a href="#" class="view-documents-link" data-quote-index="<?php echo $index; ?>">
                                <i class="fas fa-file-alt"></i> <?php echo $doc_count; ?> Document<?php echo $doc_count > 1 ? 's' : ''; ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #9ca3af;">No documents</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" action="" class="delete-form" onsubmit="return confirm('Are you sure you want to delete this quote?');">
                            <input type="hidden" name="delete_quote" value="1">
                            <input type="hidden" name="quote_index" value="<?php echo $index; ?>">
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No rates added yet.</p>
    <?php endif; ?>
    
    <!-- Documents Modal -->
    <div id="documentsModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close-modal" onclick="closeDocumentsModal()">&times;</span>
            <h2>Quote Documents</h2>
            <div id="modalDocumentsList"></div>
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const input = document.getElementById("warehouse_location");
            if (input && window.google && google.maps && google.maps.places) {
                new google.maps.places.Autocomplete(input, { types: ["geocode"], componentRestrictions: { country: "us" } });
            }

            // File upload functionality
            const fileUploadZone = document.getElementById('fileUploadZone');
            const fileInput = document.getElementById('quote_documents');
            const selectedFilesList = document.getElementById('selectedFiles');
            let selectedFiles = [];

            fileUploadZone.addEventListener('click', () => fileInput.click());

            fileUploadZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                fileUploadZone.classList.add('dragover');
            });

            fileUploadZone.addEventListener('dragleave', () => {
                fileUploadZone.classList.remove('dragover');
            });

            fileUploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadZone.classList.remove('dragover');
                const files = Array.from(e.dataTransfer.files);
                handleFiles(files);
            });

            fileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                handleFiles(files);
            });

            function handleFiles(files) {
                selectedFiles = [...selectedFiles, ...files];
                updateFilesList();
            }

            function removeFile(index) {
                selectedFiles.splice(index, 1);
                updateFilesList();
            }

            function updateFilesList() {
                if (selectedFiles.length === 0) {
                    selectedFilesList.innerHTML = '';
                    fileInput.required = true;
                    return;
                }

                fileInput.required = false;

                let html = '';
                selectedFiles.forEach((file, index) => {
                    const sizeKB = (file.size / 1024).toFixed(1);
                    html += `
                        <div class="file-item">
                            <div class="file-info">
                                <i class="fas fa-file file-icon"></i>
                                <div>
                                    <div style="font-weight: 600;">${file.name}</div>
                                    <div style="font-size: 0.85em; color: #6c757d;">${sizeKB} KB</div>
                                </div>
                            </div>
                            <button type="button" class="remove-file-btn" onclick="removeFileAt(${index})">Remove</button>
                        </div>
                    `;
                });
                selectedFilesList.innerHTML = html;

                // Update the file input with selected files
                const dataTransfer = new DataTransfer();
                selectedFiles.forEach(file => dataTransfer.items.add(file));
                fileInput.files = dataTransfer.files;
            }

            window.removeFileAt = function(index) {
                removeFile(index);
            };

            // Documents modal functionality
            const quoteDocuments = <?php echo json_encode($quote_documents); ?>;

            window.showDocumentsModal = function(quoteIndex) {
                const docs = quoteDocuments[quoteIndex] || [];
                const modal = document.getElementById('documentsModal');
                const docsList = document.getElementById('modalDocumentsList');

                if (docs.length === 0) {
                    docsList.innerHTML = '<p>No documents found.</p>';
                } else {
                    let html = '';
                    docs.forEach(doc => {
                        const sizeKB = (doc.file_size / 1024).toFixed(1);
                        html += `
                            <div class="modal-document-item">
                                <div class="modal-doc-info">
                                    <i class="fas fa-file-alt modal-doc-icon"></i>
                                    <div>
                                        <div class="modal-doc-name">${doc.original_file_name}</div>
                                        <div class="modal-doc-size">${sizeKB} KB</div>
                                    </div>
                                </div>
                                <a href="download_document.php?id=${doc.id}" class="modal-doc-download" target="_blank">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        `;
                    });
                    docsList.innerHTML = html;
                }

                modal.style.display = 'block';
            };

            window.closeDocumentsModal = function() {
                document.getElementById('documentsModal').style.display = 'none';
            };

            // Attach click handlers to view document links
            document.querySelectorAll('.view-documents-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const quoteIndex = this.getAttribute('data-quote-index');
                    showDocumentsModal(quoteIndex);
                });
            });

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('documentsModal');
                if (event.target === modal) {
                    closeDocumentsModal();
                }
            };
        });
    </script>
</main>
</body>
</html>
