<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instagram Data Deletion Status - Error</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .error-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
        }

        h1 {
            font-size: 28px;
            color: #1a202c;
            margin-bottom: 15px;
        }

        .error-message {
            color: #718096;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .error-code {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 30px;
        }

        .error-code p {
            color: #991b1b;
            font-size: 14px;
            font-weight: 600;
        }

        .help-text {
            color: #a0aec0;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 640px) {
            .container {
                padding: 30px 20px;
            }

            h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <svg class="error-icon" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="32" cy="32" r="30" fill="#EF4444" stroke="#DC2626" stroke-width="2"/>
            <path d="M32 20V36M32 44V44.01" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>

        <h1>Unable to Retrieve Status</h1>

        <p class="error-message">
            We encountered an issue while trying to retrieve the deletion status.
        </p>

        <div class="error-code">
            <p>{{ $error }}</p>
        </div>

        <div class="help-text">
            <p>If you believe this is an error, please contact our support team with your confirmation code.</p>
        </div>
    </div>
</body>
</html>
