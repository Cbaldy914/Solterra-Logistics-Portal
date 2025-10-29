<?php
session_name("logistics_session");
session_start();


if (!isset($_SESSION['user_id'])) {
header("Location: login");
exit();
}

// Initialize variables
$name = '';
$email = '';
$subject = '';
$message = '';
$success_message = '';
$error_message = '';

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate form inputs
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $message_content = trim($_POST['message']);

    if (empty($name) || empty($email) || empty($subject) || empty($message_content)) {
        $error_message = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Prepare email
        $to = 'cbaldy@solterrasol.com';
        $subject = "Portal Inquiry: $subject";
        $message_body = "Name: $name\n";
        $message_body .= "Email: $email\n";
        $message_body .= "Subject: $subject\n";
        $message_body .= "Message:\n$message_content\n";

        // Optional: Add headers
        $headers = "From: $email\r\n";
        $headers .= "Reply-To: $email\r\n";

        // Send email
        if (mail($to, $subject, $message_body, $headers)) {
            $success_message = "Your message has been sent successfully. We will get back to you shortly.";
            // Clear form fields
            $name = '';
            $email = '';
            $subject = '';
            $message = '';
        } else {
            $error_message = "There was an error sending your message. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Header Section - Matching global_documents.php */
        .global-documents-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .global-documents-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .contact-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .info-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
        }

        .info-card i {
            font-size: 2em;
            color: #488C9A;
            margin-bottom: 12px;
        }

        .info-card h3 {
            margin: 0 0 8px 0;
            color: #293E4C;
        }

        .info-card a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }

        .info-card a:hover {
            color: #3A6E7F;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <div class="global-documents-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="header-info">
                        <h1>Questions & Support</h1>
                        <p class="header-subtitle">We're here to help with any questions or concerns</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="contact-info-grid">
            <div class="info-card">
                <i class="fas fa-envelope"></i>
                <h3>Email Us</h3>
                <p><a href="mailto:info@solterrasol.com">info@solterrasol.com</a></p>
            </div>
            <div class="info-card">
                <i class="fas fa-phone"></i>
                <h3>Call Us</h3>
                <p><a href="tel:9196378842">(919) 637-8842</a></p>
            </div>
            <div class="info-card">
                <i class="fas fa-clock"></i>
                <h3>Business Hours</h3>
                <p>Monday - Friday<br>9:00 AM - 5:00 PM EST</p>
            </div>
        </div>

        <div class="contact-form">
            <?php if (!empty($success_message)): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form method="POST" action="questions">
                <label for="name">Your Name:</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required>

                <label for="email">Your Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>

                <label for="subject">Subject:</label>
                <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($subject); ?>" required>

                <label for="message">Message:</label>
                <textarea id="message" name="message" required><?php echo htmlspecialchars($message); ?></textarea>

                <input type="submit" value="Send Message">
            </form>
        </div>
    </main>
</body>
</html>
