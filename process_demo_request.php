<?php
// Initialize session
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_set_cookie_params([
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once '../config.php';
require_once 'Mailer.php';

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: request_demo");
    exit();
}

// Get and validate form data
$company_name = trim($_POST['company_name'] ?? '');
$contact_name = trim($_POST['contact_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$project_location = trim($_POST['project_location'] ?? '');
$estimated_volume = trim($_POST['estimated_volume'] ?? '');
$message = trim($_POST['message'] ?? '');

// Validate required fields
if (empty($company_name) || empty($contact_name) || empty($email)) {
    $_SESSION['demo_error'] = 'Please fill in all required fields.';
    header("Location: request_demo");
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['demo_error'] = 'Please enter a valid email address.';
    header("Location: request_demo");
    exit();
}

// Get user's IP address
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

$conn = getDBConnection();
if (!$conn) {
    $_SESSION['demo_error'] = 'System error. Please try again later.';
    header("Location: request_demo");
    exit();
}

// Check for rate limiting (optional - prevent spam)
// Allow max 3 requests per IP per day
$today_start = date('Y-m-d 00:00:00');
$check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM demo_requests WHERE ip_address = ? AND submitted_at >= ?");
$check_stmt->bind_param("ss", $ip_address, $today_start);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$check_data = $check_result->fetch_assoc();
$check_stmt->close();

if ($check_data['count'] >= 3) {
    $_SESSION['demo_error'] = 'You have reached the maximum number of requests for today. Please try again tomorrow or contact us directly.';
    header("Location: request_demo");
    exit();
}

// Insert demo request into database
$stmt = $conn->prepare("
    INSERT INTO demo_requests 
    (company_name, contact_name, email, phone, project_location, estimated_volume, message, ip_address) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "ssssssss",
    $company_name,
    $contact_name,
    $email,
    $phone,
    $project_location,
    $estimated_volume,
    $message,
    $ip_address
);

if (!$stmt->execute()) {
    $_SESSION['demo_error'] = 'Failed to submit request. Please try again.';
    $stmt->close();
    $conn->close();
    header("Location: request_demo");
    exit();
}

$request_id = $conn->insert_id;
$stmt->close();
$conn->close();

// Send notification email to sales team (optional)
// Update this email address to your sales team's email
$sales_email = "info@solterrasolutions.com"; // Change this to your actual sales email

$email_subject = "🎯 New Demo Request from " . $company_name;
$email_body = "New Demo Request - Request ID #" . $request_id . "
========================================

Company Name: " . $company_name . "
Contact Name: " . $contact_name . "
Email: " . $email . "
" . (!empty($phone) ? "Phone: " . $phone . "\n" : "") . "
" . (!empty($project_location) ? "Project Location: " . $project_location . "\n" : "") . "
" . (!empty($estimated_volume) ? "Estimated MW Volume: " . $estimated_volume . "\n" : "") . "

" . (!empty($message) ? "Message:
" . $message . "

" : "") . "Submitted: " . date('F j, Y g:i A') . "
IP Address: " . $ip_address . "

---
This request was submitted via the Solterra Solutions Portal login page.";

try {
    Mailer::send($sales_email, $email_subject, $email_body);
} catch (Exception $e) {
    // Log error but don't fail the request
    error_log("Demo request notification email failed: " . $e->getMessage());
}

// Send confirmation email to requester
$confirmation_subject = "We Received Your Request - Solterra Solutions";
$confirmation_body = "Hello " . $contact_name . ",

Thank you for reaching out to Solterra Solutions! We've received your request and are excited to show you how our logistics platform can transform your solar project management.

WHAT HAPPENS NEXT?
- A member of our team will review your request
- We'll reach out within 1-2 business days to schedule a personalized demo
- We'll discuss your specific logistics challenges and how we can help

In the meantime, if you have any urgent questions, please don't hesitate to contact us directly at info@solterrasol.com

Best regards,
The Solterra Solutions Team

---
Solterra Solutions - Streamlining Solar Logistics";

try {
    Mailer::send($email, $confirmation_subject, $confirmation_body);
} catch (Exception $e) {
    // Log error but don't fail the request
    error_log("Demo request confirmation email failed: " . $e->getMessage());
}

$_SESSION['demo_success'] = "Your request has been submitted successfully! We'll contact you within 1-2 business days.";
header("Location: request_demo");
exit();
?>

