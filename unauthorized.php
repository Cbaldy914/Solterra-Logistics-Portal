<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Solterra Solutions</title>
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #293E4C 0%, #1f2f3a 50%, #488C9A 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            overflow-x: hidden;
        }

        .unauth-card {
            width: 100%;
            max-width: 520px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow:
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 100px rgba(107, 184, 199, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            padding: 3rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            text-align: center;
            animation: fadeInUp 0.6s ease-out;
        }

        .unauth-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ef4444 0%, #f87171 50%, #ef4444 100%);
            background-size: 200% 100%;
            animation: shimmer 3s linear infinite;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes shimmer {
            0%   { background-position: -200% 0; }
            100% { background-position:  200% 0; }
        }

        .unauth-icon {
            width: 88px;
            height: 88px;
            margin: 0 auto 1.75rem;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.15);
        }

        .unauth-icon svg {
            width: 40px;
            height: 40px;
            color: #dc2626;
        }

        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #293E4C;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        .unauth-subtitle {
            font-size: 1rem;
            color: #6b7280;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .unauth-detail {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 2rem;
            font-size: 0.875rem;
            color: #4b5563;
            line-height: 1.6;
        }

        .unauth-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 12px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #6BB8C7 100%);
            color: #fff;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(72, 140, 154, 0.4);
        }

        .btn-secondary {
            background: #fff;
            color: #488C9A;
            border: 2px solid #e5e8eb;
        }

        .btn-secondary:hover {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.04);
            transform: translateY(-1px);
        }

        .btn:active { transform: translateY(0); }

        .btn svg { width: 18px; height: 18px; }

        @media (max-width: 575.98px) {
            .unauth-card { padding: 2rem 1.5rem; border-radius: 20px; }
            h1 { font-size: 1.5rem; }
            .unauth-icon { width: 72px; height: 72px; }
            .unauth-icon svg { width: 32px; height: 32px; }
        }
    </style>
</head>
<body>
    <div class="unauth-card">
        <div class="unauth-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
        </div>

        <h1>Access Denied</h1>
        <p class="unauth-subtitle">You don't have permission to view this page.</p>

        <div class="unauth-detail">
            If you believe this is an error, please contact your account administrator or reach out to the Solterra Solutions support team.
        </div>

        <div class="unauth-actions">
            <a href="dashboard" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                Go to Dashboard
            </a>
            <a href="login" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                </svg>
                Return to Login
            </a>
        </div>
    </div>
</body>
</html>
