<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo img {
            max-width: 150px;
        }
        h1 {
            color: #1a1a1a;
            font-size: 24px;
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 15px;
            color: #555;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 30px;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #1d4ed8;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #888;
        }
        .link-text {
            word-break: break-all;
            font-size: 12px;
            color: #666;
            background-color: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="https://reprophotos.com/logo.png" alt="REPro Photos">
        </div>
        
        <h1>Reset Your Password</h1>
        
        <p>Hi {{ $user->name }},</p>
        
        <p>We received a request to reset your password for your REPro Photos account. Click the button below to create a new password:</p>
        
        <p style="text-align: center;">
            <a href="{{ $resetLink }}" class="button">Reset Password</a>
        </p>
        
        <p>This link will expire in 60 minutes for security reasons.</p>
        
        <p>If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>
        
        <div class="link-text">
            <strong>Can't click the button?</strong> Copy and paste this link into your browser:<br>
            {{ $resetLink }}
        </div>
        
        <div class="footer">
            <p>This email was sent by REPro Photos.<br>
            If you have any questions, please contact us at <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a></p>
        </div>
    </div>
</body>
</html>
