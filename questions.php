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

// Fetch user info for auto-population
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

$prefill_name = '';
$prefill_email = '';
if ($userData) {
    $fn = trim($userData['first_name'] ?? '');
    $ln = trim($userData['last_name'] ?? '');
    $prefill_name = trim("$fn $ln");
    $prefill_email = trim($userData['email'] ?? '');
}

// Default form values to prefilled data
$name = $prefill_name;
$email = $prefill_email;

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
        $email_subject = "Portal Inquiry: $subject";
        $message_body = "Name: $name\n";
        $message_body .= "Email: $email\n";
        $message_body .= "Subject: $subject\n";
        $message_body .= "Message:\n$message_content\n";

        $headers = "From: $email\r\n";
        $headers .= "Reply-To: $email\r\n";

        if (mail($to, $email_subject, $message_body, $headers)) {
            $success_message = "Your message has been sent successfully. We'll get back to you shortly.";
            $name = '';
            $email = '';
            $subject = '';
            $message = '';
        } else {
            $error_message = "There was an error sending your message. Please try again later.";
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
    <title>Questions & Support</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Header Section */
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

        /* Contact Info Cards */
        .contact-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .info-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }

        .info-card-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .info-card-icon i {
            font-size: 1.4em;
            color: #488C9A;
        }

        .info-card h3 {
            margin: 0 0 8px 0;
            color: #293E4C;
            font-size: 1.05em;
            font-weight: 600;
        }

        .info-card p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .info-card a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }

        .info-card a:hover {
            color: #3A6E7F;
            text-decoration: underline;
        }

        /* Contact Form Section */
        .contact-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .contact-section {
                grid-template-columns: 1fr;
            }
        }

        .contact-form-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .contact-form-card h2 {
            font-size: 1.5em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 6px 0;
        }

        .contact-form-card .form-desc {
            color: #6c757d;
            font-size: 0.95em;
            margin: 0 0 28px 0;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 6px;
            font-size: 0.9em;
        }

        .form-group label .required-star {
            color: #e74c3c;
            margin-left: 2px;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #dde2e6;
            border-radius: 10px;
            font-size: 0.95em;
            font-family: 'Poppins', sans-serif;
            color: #293E4C;
            background: #f8f9fa;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.12);
        }

        .form-group input.prefilled {
            background: #eef6f7;
            border-color: #b8d8de;
        }

        .form-group input.prefilled:focus {
            background: #ffffff;
            border-color: #488C9A;
        }

        .form-group textarea {
            min-height: 140px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group .input-hint {
            font-size: 0.8em;
            color: #8e99a4;
            margin-top: 4px;
        }

        .submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1em;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 4px 16px rgba(72, 140, 154, 0.3);
            width: 100%;
            justify-content: center;
            margin-top: 8px;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(72, 140, 154, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Sidebar */
        .contact-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .sidebar-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .sidebar-card h3 {
            font-size: 1.15em;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-card h3 i {
            color: #488C9A;
            font-size: 0.95em;
        }

        .faq-item {
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f2f5;
        }

        .faq-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .faq-item strong {
            display: block;
            color: #293E4C;
            font-size: 0.9em;
            margin-bottom: 4px;
        }

        .faq-item p {
            margin: 0;
            color: #6c757d;
            font-size: 0.85em;
            line-height: 1.5;
        }

        .response-time-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(72, 140, 154, 0.05) 100%);
            padding: 12px 20px;
            border-radius: 12px;
            color: #3A6E7F;
            font-weight: 600;
            font-size: 0.9em;
            margin-top: 8px;
        }

        .response-time-badge i {
            font-size: 1.1em;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95em;
            font-weight: 500;
        }

        .alert i {
            font-size: 1.2em;
            flex-shrink: 0;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #e8f5e9 100%);
            color: #1b5e20;
            border: 1px solid #a5d6a7;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #fde8ea 100%);
            color: #b71c1c;
            border: 1px solid #ef9a9a;
        }

        /* Subject select */
        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236c757d' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <?php
            require_once 'components/breadcrumbs.php';
            echo slp_render_breadcrumbs(['current_label' => 'Questions & Support']);
        ?>
        <div class="global-documents-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="header-icon">
                        <i class="fas fa-headset"></i>
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
                <div class="info-card-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h3>Email Us</h3>
                <p><a href="mailto:info@solterrasol.com">info@solterrasol.com</a></p>
            </div>
            <div class="info-card">
                <div class="info-card-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <h3>Call Us</h3>
                <p><a href="tel:9196378842">(919) 637-8842</a></p>
            </div>
            <div class="info-card">
                <div class="info-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3>Business Hours</h3>
                <p>Monday - Friday<br>9:00 AM - 5:00 PM EST</p>
            </div>
        </div>

        <div class="contact-section">
            <div class="contact-form-card">
                <h2><i class="fas fa-paper-plane" style="color: #488C9A; margin-right: 10px; font-size: 0.85em;"></i>Send Us a Message</h2>
                <p class="form-desc">Fill out the form below and our team will respond as soon as possible.</p>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="questions" id="contactForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Full Name <span class="required-star">*</span></label>
                            <input type="text" id="name" name="name"
                                   value="<?php echo htmlspecialchars($name); ?>"
                                   placeholder="Your full name"
                                   class="<?php echo (!empty($prefill_name) && $name === $prefill_name) ? 'prefilled' : ''; ?>"
                                   required>
                            <?php if (!empty($prefill_name) && $name === $prefill_name): ?>
                                <div class="input-hint"><i class="fas fa-check" style="color: #488C9A; margin-right: 4px;"></i>Auto-filled from your account</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="required-star">*</span></label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($email); ?>"
                                   placeholder="you@company.com"
                                   class="<?php echo (!empty($prefill_email) && $email === $prefill_email) ? 'prefilled' : ''; ?>"
                                   required>
                            <?php if (!empty($prefill_email) && $email === $prefill_email): ?>
                                <div class="input-hint"><i class="fas fa-check" style="color: #488C9A; margin-right: 4px;"></i>Auto-filled from your account</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject <span class="required-star">*</span></label>
                        <select id="subject" name="subject" required>
                            <option value="" disabled <?php echo empty($subject) ? 'selected' : ''; ?>>Select a topic...</option>
                            <option value="General Inquiry" <?php echo ($subject === 'General Inquiry') ? 'selected' : ''; ?>>General Inquiry</option>
                            <option value="Delivery Question" <?php echo ($subject === 'Delivery Question') ? 'selected' : ''; ?>>Delivery Question</option>
                            <option value="Module/Batch Issue" <?php echo ($subject === 'Module/Batch Issue') ? 'selected' : ''; ?>>Module / Batch Issue</option>
                            <option value="Account & Access" <?php echo ($subject === 'Account & Access') ? 'selected' : ''; ?>>Account & Access</option>
                            <option value="Billing & Invoicing" <?php echo ($subject === 'Billing & Invoicing') ? 'selected' : ''; ?>>Billing & Invoicing</option>
                            <option value="Bug Report" <?php echo ($subject === 'Bug Report') ? 'selected' : ''; ?>>Bug Report</option>
                            <option value="Feature Request" <?php echo ($subject === 'Feature Request') ? 'selected' : ''; ?>>Feature Request</option>
                            <option value="Other" <?php echo ($subject === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="message">Message <span class="required-star">*</span></label>
                        <textarea id="message" name="message" placeholder="Describe your question or issue in detail..." required><?php echo htmlspecialchars($message); ?></textarea>
                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i>
                        Send Message
                    </button>
                </form>
            </div>

            <div class="contact-sidebar">
                <div class="sidebar-card">
                    <h3><i class="fas fa-bolt"></i> Quick Answers</h3>
                    <div class="faq-item">
                        <strong>How do I track my deliveries?</strong>
                        <p>Navigate to "Manage Deliveries" from the main menu to view all delivery statuses and tracking information.</p>
                    </div>
                    <div class="faq-item">
                        <strong>How do I update module batch details?</strong>
                        <p>Go to the Modules page, select your batch, and use the edit options available on the batch overview page.</p>
                    </div>
                    <div class="faq-item">
                        <strong>Who can I contact for urgent issues?</strong>
                        <p>For urgent matters, call us directly at <a href="tel:9196378842">(919) 637-8842</a> during business hours.</p>
                    </div>
                </div>

                <div class="sidebar-card">
                    <h3><i class="fas fa-clock"></i> Response Time</h3>
                    <p style="color: #6c757d; font-size: 0.9em; margin: 0 0 12px 0;">We typically respond to inquiries within one business day.</p>
                    <div class="response-time-badge">
                        <i class="fas fa-bolt"></i>
                        Average response: &lt; 24 hours
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
