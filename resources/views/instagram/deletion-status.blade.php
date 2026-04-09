<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instagram Data Deletion Status</title>
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
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header svg {
            width: 64px;
            height: 64px;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 28px;
            color: #1a202c;
            margin-bottom: 10px;
        }

        .header p {
            color: #718096;
            font-size: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-processing {
            background: #fff3cd;
            color: #856404;
        }

        .status-pending {
            background: #cce5ff;
            color: #004085;
        }

        .info-section {
            margin-top: 30px;
            padding: 20px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #718096;
            font-weight: 500;
        }

        .info-value {
            color: #1a202c;
            font-weight: 600;
        }

        .confirmation-code {
            margin-top: 30px;
            padding: 15px;
            background: #edf2f7;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .confirmation-code p {
            color: #4a5568;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .confirmation-code code {
            background: white;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: #2d3748;
            display: block;
            word-break: break-all;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #a0aec0;
            font-size: 14px;
        }

        @media (max-width: 640px) {
            .container {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .info-row {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="32" cy="32" r="30" fill="#10B981" stroke="#059669" stroke-width="2"/>
                <path d="M20 32L28 40L44 24" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h1>Data Deletion Completed</h1>
            <p>Your Instagram data has been successfully deleted from our system</p>
            <span class="status-badge status-{{ strtolower($log->status) }}">
                {{ ucfirst($log->status) }}
            </span>
        </div>

        <div class="info-section">
            <div class="info-row">
                <span class="info-label">Instagram User ID</span>
                <span class="info-value">{{ $log->instagram_user_id }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Request Date</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($log->requested_at)->format('M d, Y H:i:s') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Completion Date</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($log->completed_at)->format('M d, Y H:i:s') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Connections Deleted</span>
                <span class="info-value">{{ $log->connections_deleted }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Conversations Deleted</span>
                <span class="info-value">{{ $log->conversations_deleted }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Messages Deleted</span>
                <span class="info-value">{{ $log->messages_deleted }}</span>
            </div>
        </div>

        <div class="confirmation-code">
            <p><strong>Confirmation Code:</strong></p>
            <code>{{ $log->confirmation_code }}</code>
        </div>

        <div class="footer">
            <p>This page is provided for your records. Your data has been permanently deleted as per your request.</p>
        </div>
    </div>
</body>
</html>
