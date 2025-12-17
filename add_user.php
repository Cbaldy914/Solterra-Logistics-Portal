<?php
session_name("logistics_session");
session_start();

// Ensure the user is a global admin
if ($_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize feedback messages
$success_message = '';
$error_message = '';
$form_data = [
    'username' => '',
    'role' => 'user',
    'account_id' => ''
]; // Store form values to repopulate after error

// 1) Fetch all accounts for the dropdown
$accounts = [];
$stmtAcc = $conn->prepare("SELECT id, name FROM customer_accounts ORDER BY name ASC");
$stmtAcc->execute();
$resultAcc = $stmtAcc->get_result();
if ($resultAcc && $resultAcc->num_rows > 0) {
    while ($row = $resultAcc->fetch_assoc()) {
        $accounts[] = $row;
    }
}
$stmtAcc->close();

// 2) If the form is submitted, handle the POST to create a user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data for repopulation if there's an error
    $form_data = [
        'username' => $_POST['username'] ?? '',
        'role' => $_POST['role'] ?? 'user',
        'account_id' => intval($_POST['account_id'] ?? 0)
    ];

    $username   = $_POST['username'];
    $password   = $_POST['password'];
    $role       = $_POST['role'];             // e.g. 'user', 'admin', 'DDPm'
    $account_id = intval($_POST['account_id']);

    // Basic validation
    if (empty($username) || empty($password) || empty($role) || empty($account_id)) {
        $error_message = "All fields (username, password, role, account) are required.";
    } else {
        try {
            // Begin transaction for user creation process
            $conn->begin_transaction();
            
            // Insert into `users`
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmtUser = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmtUser->bind_param("ss", $username, $hashed_password);

            if (!$stmtUser->execute()) {
                throw new Exception($stmtUser->error, $stmtUser->errno);
            }
            
            // Get new user ID
            $user_id = $stmtUser->insert_id;
            $stmtUser->close();

            // Insert bridging row (customer_account_users)
            $stmtCAU = $conn->prepare("
                INSERT INTO customer_account_users (user_id, account_id, role)
                VALUES (?, ?, ?)
            ");
            $stmtCAU->bind_param("iis", $user_id, $account_id, $role);

            if (!$stmtCAU->execute()) {
                throw new Exception($stmtCAU->error, $stmtCAU->errno);
            }
            
            $stmtCAU->close();
            
            // If we got here, everything succeeded
            $conn->commit();
            $success_message = "New user '{$username}' added and assigned to account successfully.";
            
            // Clear form data after successful submission
            $form_data = [
                'username' => '',
                'role' => 'user',
                'account_id' => ''
            ];
            
        } catch (Exception $e) {
            // Roll back the transaction on error
            $conn->rollback();
            
            // Handle duplicate entry error specifically
            if ($e->getCode() == 1062) { // MySQL error code for duplicate entry
                $error_message = "Username '{$username}' already exists. Please choose a different username.";
            } else {
                $error_message = "Error creating user: " . $e->getMessage();
            }
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
    <title>Add User</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .container {
            padding: 20px;
        }
        
        .form-container {
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 25px;
            margin-top: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="password"]:focus,
        .form-group select:focus {
            border-color: #488C9A;
            outline: none;
            box-shadow: 0 0 5px rgba(72,140,154,0.3);
        }
        
        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            font-weight: 500;
        }
        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: #488C9A;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background-color: #3A7A87;
        }
        
        .action-buttons {
            margin-top: 20px;
            background: transparent;
        }
        
        .required-indicator {
            color: #dc3545;
            margin-left: 3px;
        }
        
        .form-title {
            margin-bottom: 25px;
            color: #293E4C;
            font-size: 1.5rem;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Add New User',
            'extra' => [ ['label' => 'Manage Users', 'url' => 'manage_users.php'] ]
        ]);
    ?>

    <h1>Add New User</h1>

    <!-- Display success or error messages -->
    <?php if (!empty($error_message)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <!-- The form remains visible so you can add more users if desired -->
    <div class="form-container">
        <h2 class="form-title">User Information</h2>
        <form action="" method="POST">
            <div class="form-group">
                <label for="username">Username <span class="required-indicator">*</span></label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username']); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required-indicator">*</span></label>
                <input type="password" id="password" name="password" required>
                <small style="display: block; margin-top: 5px; color: #6c757d;">Password should be at least 8 characters long.</small>
            </div>

            <div class="form-group">
                <label for="role">Role <span class="required-indicator">*</span></label>
                <select id="role" name="role" required>
                    <option value="user" <?php echo ($form_data['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                    <option value="customer_admin" <?php echo ($form_data['role'] === 'customer_admin') ? 'selected' : ''; ?>>Customer Admin</option>
                    <option value="admin" <?php echo ($form_data['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                    <option value="DDPm" <?php echo ($form_data['role'] === 'DDPm') ? 'selected' : ''; ?>>DDPm</option>
                    <option value="global_admin" <?php echo ($form_data['role'] === 'global_admin') ? 'selected' : ''; ?>>Global Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label for="account_id">Assign to Account <span class="required-indicator">*</span></label>
                <select id="account_id" name="account_id" required>
                    <option value="">--Select an Account--</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($form_data['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="action-buttons">
                <button type="submit" class="btn-primary">Add User</button>
            </div>
        </form>
    </div>
</main>
</body>
</html>
