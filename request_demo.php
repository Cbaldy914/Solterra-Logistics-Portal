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

// Check for success message
$success_message = '';
if (isset($_SESSION['demo_success'])) {
    $success_message = $_SESSION['demo_success'];
    unset($_SESSION['demo_success']);
}

$error_message = '';
if (isset($_SESSION['demo_error'])) {
    $error_message = $_SESSION['demo_error'];
    unset($_SESSION['demo_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Information - Solterra Solutions</title>
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #293E4C 0%, #1f2f3a 50%, #488C9A 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Subtle animated background elements */
        body::before,
        body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
            pointer-events: none;
        }

        body::before {
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, #6BB8C7 0%, transparent 70%);
            top: -200px;
            right: -200px;
            animation: float 20s ease-in-out infinite;
        }

        body::after {
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, #488C9A 0%, transparent 70%);
            bottom: -300px;
            left: -300px;
            animation: float 25s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, 30px) scale(1.1); }
        }

        .demo-container {
            width: 100%;
            max-width: 1100px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 100px rgba(107, 184, 199, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            overflow: hidden;
            position: relative;
            z-index: 1;
            animation: fadeInUp 0.8s ease-out;
        }

        .demo-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #488C9A 0%, #6BB8C7 50%, #488C9A 100%);
            background-size: 200% 100%;
            animation: shimmer 3s linear infinite;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .demo-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 600px;
        }

        .demo-left {
            padding: 4rem;
            background: linear-gradient(135deg, #f8fafc 0%, #e0f2f7 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .demo-left::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 1px;
            height: 100%;
            background: linear-gradient(to bottom, transparent, rgba(72, 140, 154, 0.2), transparent);
        }

        .demo-logo {
            width: 120px;
            height: 120px;
            margin-bottom: 2.5rem;
            filter: drop-shadow(0 4px 12px rgba(72, 140, 154, 0.3));
        }

        .demo-left h1 {
            font-size: 2.75rem;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 1rem;
            letter-spacing: -0.03em;
            line-height: 1.2;
        }

        .demo-left .tagline {
            font-size: 1.25rem;
            color: #488C9A;
            font-weight: 500;
            margin-bottom: 2.5rem;
            line-height: 1.5;
        }

        .benefits-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .benefits-list li {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
            font-size: 1rem;
            color: #1f2937;
            line-height: 1.6;
        }

        .benefits-list li::before {
            content: '✓';
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 50%;
            font-weight: 700;
            flex-shrink: 0;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .demo-right {
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .demo-right h2 {
            font-size: 1.75rem;
            color: #293E4C;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .demo-right .subtitle {
            font-size: 0.95rem;
            color: #6b7280;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }

        .form-group label .required {
            color: #ef4444;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.875rem 1.125rem;
            font-size: 0.95rem;
            border: 2px solid #e5e8eb;
            border-radius: 12px;
            background: #ffffff;
            color: #1f2937;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #488C9A;
            background: #ffffff;
            box-shadow: 
                0 0 0 4px rgba(72, 140, 154, 0.1),
                0 1px 2px rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #9ca3af;
        }

        .submit-btn {
            width: 100%;
            padding: 1rem 1.5rem;
            font-size: 1.05rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #488C9A 0%, #6BB8C7 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 
                0 4px 12px rgba(72, 140, 154, 0.3),
                0 1px 2px rgba(0, 0, 0, 0.1);
            margin-top: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .submit-btn:hover::before {
            left: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 8px 20px rgba(72, 140, 154, 0.4),
                0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #488C9A;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .back-link:hover {
            color: #3A6E7F;
            text-decoration: underline;
        }

        .message {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            animation: slideDown 0.3s ease-out;
        }

        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .success-container {
            padding: 6rem 4rem;
            text-align: center;
        }

        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            animation: scaleIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
        }

        .success-icon svg {
            width: 56px;
            height: 56px;
            fill: white;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0) rotate(-180deg);
                opacity: 0;
            }
            to {
                transform: scale(1) rotate(0);
                opacity: 1;
            }
        }

        .success-container h1 {
            font-size: 2.5rem;
            color: #293E4C;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .success-container p {
            font-size: 1.125rem;
            color: #6b7280;
            line-height: 1.7;
            max-width: 500px;
            margin: 0 auto 2rem;
        }

        @media (max-width: 1024px) {
            .demo-content {
                grid-template-columns: 1fr;
            }

            .demo-left::after {
                display: none;
            }

            .demo-left {
                padding: 3rem 2rem;
            }

            .demo-right {
                padding: 3rem 2rem;
            }

            .demo-left h1 {
                font-size: 2.25rem;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 2rem 1rem;
            }

            .demo-container {
                border-radius: 24px;
            }

            .demo-left,
            .demo-right {
                padding: 2rem 1.5rem;
            }

            .demo-left h1 {
                font-size: 2rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .success-container {
                padding: 3rem 2rem;
            }

            .success-container h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="demo-container">
        <?php if (!empty($success_message)): ?>
            <div class="success-container">
                <div class="success-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                        <path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/>
                    </svg>
                </div>
                <h1>Thank You!</h1>
                <p><?php echo htmlspecialchars($success_message); ?></p>
                <a href="login" class="back-link">← Back to Login</a>
            </div>
        <?php else: ?>
            <div class="demo-content">
                <!-- Left Side: Benefits -->
                <div class="demo-left">
                    <img src="pictures/main_logo.png" alt="Solterra Solutions Logo" class="demo-logo">
                    <h1>Streamline Your Solar Operations</h1>
                    <p class="tagline">Join leading solar developers in transforming project management</p>
                    
                    <ul class="benefits-list">
                        <li>Track solar modules from manufacturer to jobsite delivery in real-time</li>
                        <li>Centralized document management for compliance and operations</li>
                        <li>Multi-warehouse inventory coordination across locations</li>
                        <li>Financial tracking and reporting for your project costs</li>
                        <li>Secure, enterprise-grade platform trusted by industry leaders</li>
                    </ul>
                </div>

                <!-- Right Side: Form -->
                <div class="demo-right">
                    <h2>Request Information</h2>
                    <p class="subtitle">Let us show you how Solterra Solutions can transform your operations</p>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
                    <?php endif; ?>
                    
                    <form action="process_demo_request.php" method="POST">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="company_name">Company Name <span class="required">*</span></label>
                                <input type="text" id="company_name" name="company_name" placeholder="Your company" required>
                            </div>
                            <div class="form-group">
                                <label for="contact_name">Your Name <span class="required">*</span></label>
                                <input type="text" id="contact_name" name="contact_name" placeholder="Full name" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email Address <span class="required">*</span></label>
                                <input type="email" id="email" name="email" placeholder="you@company.com" required>
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" placeholder="(555) 123-4567">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="project_location">Project Location(s)</label>
                                <input type="text" id="project_location" name="project_location" placeholder="City, State or Region">
                            </div>
                            <div class="form-group">
                                <label for="estimated_volume">Estimated MW Volume</label>
                                <select id="estimated_volume" name="estimated_volume">
                                    <option value="">Select range</option>
                                    <option value="0-50 MW">0-50 MW</option>
                                    <option value="50-100 MW">50-100 MW</option>
                                    <option value="100-250 MW">100-250 MW</option>
                                    <option value="250-500 MW">250-500 MW</option>
                                    <option value="500+ MW">500+ MW</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="message">Tell Us About Your Needs</label>
                            <textarea id="message" name="message" placeholder="What logistics challenges are you facing? What features are most important to you?"></textarea>
                        </div>
                        
                        <button type="submit" class="submit-btn">
                            <span>Submit Request</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576 6.636 10.07Zm6.787-8.201L1.591 6.602l4.339 2.76 7.494-7.493Z"/>
                            </svg>
                        </button>
                    </form>
                    
                    <a href="login" class="back-link">← Back to Login</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
