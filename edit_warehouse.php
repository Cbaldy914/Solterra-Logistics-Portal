<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

$errorMessage = '';
$successMessage = '';
$warehouse = null;

// Get warehouse ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: manage_warehouses.php");
    exit();
}

$warehouse_id = intval($_GET['id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $name = trim($_POST['name'] ?? '');
        $street_address = trim($_POST['street_address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $zip_code = trim($_POST['zip_code'] ?? '');
        $in_fee = floatval($_POST['in_fee'] ?? 0);
        $out_fee = floatval($_POST['out_fee'] ?? 0);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee'] ?? 0);

        if (empty($name)) {
            throw new Exception("Warehouse name is required.");
        }

        // Check if at least one address field is provided
        if (empty($street_address) && empty($city) && empty($state) && empty($zip_code)) {
            throw new Exception("At least one address field is required.");
        }

        // Combine address fields for the main address field (for backward compatibility)
        $address_parts = array_filter([$street_address, $city, $state, $zip_code]);
        $combined_address = implode(', ', $address_parts);

        // Update warehouse
        $stmt = $conn->prepare("UPDATE warehouses SET name = ?, address = ?, street_address = ?, city = ?, state = ?, zip_code = ?, in_fee = ?, out_fee = ?, monthly_storage_fee = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing update statement: " . $conn->error);
        }

        $stmt->bind_param("ssssssdddi", $name, $combined_address, $street_address, $city, $state, $zip_code, $in_fee, $out_fee, $monthly_storage_fee, $warehouse_id);
        
        if ($stmt->execute()) {
            $successMessage = "Warehouse updated successfully!";
            // Refresh warehouse data
            $warehouse = null; // Will be fetched again below
        } else {
            throw new Exception("Error updating warehouse: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Fetch warehouse data
if (!$warehouse) {
    try {
        $stmt = $conn->prepare("SELECT id, name, address, street_address, city, state, zip_code, in_fee, out_fee, monthly_storage_fee FROM warehouses WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing select statement: " . $conn->error);
        }

        $stmt->bind_param("i", $warehouse_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception("Warehouse not found.");
        }

        $warehouse = $result->fetch_assoc();
        $stmt->close();

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

$conn->close();

// If we couldn't fetch the warehouse, redirect back
if (!$warehouse && empty($successMessage)) {
    header("Location: manage_warehouses.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warehouse</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .form-container {
            max-width: 800px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.1);
        }
        .required {
            color: red;
        }
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .fee-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: #488C9A;
            color: white;
        }
        .btn-primary:hover {
            background-color: #293E4C;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .address-grid {
                grid-template-columns: 1fr;
            }
            .fee-grid {
                grid-template-columns: 1fr;
            }
            .button-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="admin_dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <a href="manage_warehouses.php" style="color: #488C9A; text-decoration: none;">Manage Warehouses</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Edit Warehouse</span>
    </div>

    <div class="form-container">
        <h1 style="margin-bottom: 30px; color: #333;">Edit Warehouse</h1>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMessage)): ?>
            <div class="success-message">
                <strong><?php echo htmlspecialchars($successMessage); ?></strong>
            </div>
        <?php endif; ?>

        <?php if ($warehouse): ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="name">Warehouse Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($warehouse['name']); ?>" required>
            </div>

            <h3 style="margin: 30px 0 15px 0; color: #333;">Address Information</h3>
            <div class="address-grid">
                <div class="form-group">
                    <label for="street_address">Street Address</label>
                    <input type="text" id="street_address" name="street_address" value="<?php echo htmlspecialchars($warehouse['street_address'] ?? ''); ?>" placeholder="123 Main Street">
                </div>
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($warehouse['city'] ?? ''); ?>" placeholder="Phoenix">
                </div>
                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($warehouse['state'] ?? ''); ?>" placeholder="AZ">
                </div>
                <div class="form-group">
                    <label for="zip_code">ZIP Code</label>
                    <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($warehouse['zip_code'] ?? ''); ?>" placeholder="85001">
                </div>
            </div>
            <small style="color: #666; font-style: italic;">At least one address field is required</small>

            <h3 style="margin: 30px 0 15px 0; color: #333;">Fee Information</h3>
            <div class="fee-grid">
                <div class="form-group">
                    <label for="in_fee">Incoming Fee ($)</label>
                    <input type="number" id="in_fee" name="in_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['in_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="out_fee">Outgoing Fee ($)</label>
                    <input type="number" id="out_fee" name="out_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['out_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label for="monthly_storage_fee">Monthly Storage Fee ($)</label>
                    <input type="number" id="monthly_storage_fee" name="monthly_storage_fee" step="0.01" min="0" value="<?php echo number_format($warehouse['monthly_storage_fee'], 2, '.', ''); ?>" placeholder="0.00">
                </div>
            </div>

            <div class="button-group">
                <button type="submit" class="btn btn-primary">Update Warehouse</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html> 