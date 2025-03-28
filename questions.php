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
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <h1>Questions & Support</h1>
        <div class="contact-info">
            <h2>We're here to help!</h2>
            <p>If you have any questions, need assistance, or want to report an error, please use the form below or contact us directly.</p>
            <p><strong>Email:</strong> <a href="mailto:info@solterrasol.com">info@solterrasol.com</a></p>
            <p><strong>Phone:</strong> <a href="tel:9196378842">(919) 637-8842</a></p>
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
