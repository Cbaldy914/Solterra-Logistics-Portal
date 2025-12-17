<?php
// Ensure only admins (or global_admin) can access
session_name("logistics_session");
session_start();

// If you've already started storing 'global_admin', you could allow that too
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Initialize any feedback messages
$success_message = '';
$error_message = '';
$form_data = [
    'account_name' => '',
    'username' => '',
    'role' => 'admin'
]; // Store form values to repopulate after error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data for repopulation if there's an error
    $form_data = [
        'account_name' => trim($_POST['account_name'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'role' => trim($_POST['role'] ?? 'admin')
    ];
    
    // 1) Grab form inputs
    $account_name = trim($_POST['account_name']);
    $username     = trim($_POST['username']);
    $password     = trim($_POST['password']);
    $role         = trim($_POST['role']);  // 'admin' or 'user'

    // Basic validation
    if (empty($account_name) || empty($username) || empty($password) || empty($role)) {
        $error_message = "All fields are required.";
    } else {
        // Start a transaction so we can roll back if something fails
        $conn->begin_transaction();

        try {
            // 3) Create a new account row
            //    (You might check if $account_name already exists if you don't want duplicates)
            $stmtAcc = $conn->prepare("
                INSERT INTO customer_accounts (name)
                VALUES (?)
            ");
            if (!$stmtAcc) {
                throw new Exception("Error preparing account statement: " . $conn->error);
            }
            $stmtAcc->bind_param("s", $account_name);
            if (!$stmtAcc->execute()) {
                throw new Exception("Error executing account statement: " . $stmtAcc->error);
            }
            $account_id = $stmtAcc->insert_id; 
            $stmtAcc->close();

            // 4) Check if user already exists in `users`
            $stmtUserCheck = $conn->prepare("
                SELECT id, password FROM users WHERE username = ?
            ");
            $stmtUserCheck->bind_param("s", $username);
            $stmtUserCheck->execute();
            $result = $stmtUserCheck->get_result();
            
            if ($result->num_rows > 0) {
                // User already exists
                $row = $result->fetch_assoc();
                $user_id = $row['id'];
                // You could decide whether to ignore the submitted password or update it
                // For now, let's RE-hash (overwrite) if they provided a new password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmtUpdateUser = $conn->prepare("
                    UPDATE users SET password = ? WHERE id = ?
                ");
                $stmtUpdateUser->bind_param("si", $hashed_password, $user_id);
                $stmtUpdateUser->execute();
                $stmtUpdateUser->close();
            } else {
                // Create a brand new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmtNewUser = $conn->prepare("
                    INSERT INTO users (username, password) 
                    VALUES (?, ?)
                ");
                $stmtNewUser->bind_param("ss", $username, $hashed_password);
                $stmtNewUser->execute();
                $user_id = $stmtNewUser->insert_id;
                $stmtNewUser->close();
            }
            $stmtUserCheck->close();

            // 5) Insert into `customer_account_users`
            //    This ties the user to the newly created account with the chosen role
            $stmtCAU = $conn->prepare("
                INSERT INTO customer_account_users (user_id, account_id, role)
                VALUES (?, ?, ?)
            ");
            $stmtCAU->bind_param("iis", $user_id, $account_id, $role);
            $stmtCAU->execute();
            $stmtCAU->close();

            // Commit transaction
            $conn->commit();

            $success_message = "New account '$account_name' created, and user '$username' assigned as '$role'.";
            
            // Clear form data after successful submission
            $form_data = [
                'account_name' => '',
                'username' => '',
                'role' => 'admin'
            ];
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating account/user: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Account</title>
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
            max-width: 700px;
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
        
        small {
            display: block;
            margin-top: 5px;
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
            'current_label' => 'Add New Account',
            'extra' => [ ['label' => 'Manage Accounts', 'url' => 'manage_accounts.php'] ]
        ]);
    ?>

    <h1>Add New Customer Account</h1>

    <?php if (!empty($success_message)): ?>
        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="form-container">
        <h2 class="form-title">Account Details</h2>
        <form action="" method="POST">
            <div class="form-group">
                <label for="account_name">Account Name <span class="required-indicator">*</span></label>
                <input type="text" id="account_name" name="account_name" value="<?php echo htmlspecialchars($form_data['account_name']); ?>" required>
            </div>

            <div class="form-group">
                <label for="username">User to Assign <span class="required-indicator">*</span></label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($form_data['username']); ?>" required>
                <small>Enter username to assign to this account. Can be new or existing.</small>
            </div>

            <div class="form-group">
                <label for="password">User's Password <span class="required-indicator">*</span></label>
                <input type="password" id="password" name="password" required>
                <small>If an existing user is provided, this will reset their password.</small>
            </div>

            <div class="form-group">
                <label for="role">Account Role <span class="required-indicator">*</span></label>
                <select id="role" name="role">
                    <option value="user" <?php echo ($form_data['role'] === 'user') ? 'selected' : ''; ?>>User</option>
                    <option value="customer_admin" <?php echo ($form_data['role'] === 'customer_admin') ? 'selected' : ''; ?>>Customer Admin</option>
                    <option value="admin" <?php echo ($form_data['role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
                    <option value="DDPm" <?php echo ($form_data['role'] === 'DDPm') ? 'selected' : ''; ?>>DDPm</option>
                </select>
            </div>

            <button type="submit" class="btn-primary">Create Account & Assign User</button>
        </form>
    </div>
</main>
</body>
</html>
