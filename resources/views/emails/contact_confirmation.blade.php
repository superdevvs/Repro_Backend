<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Contacting Us</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 20px;
        }
        .content {
            padding: 20px 0;
        }
        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0; font-size: 24px; color: #1f2937;">Thank You!</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $submission->sender_name }},</p>
        
        <p>Thank you for reaching out to {{ $client->name ?? 'us' }}. We have received your message and will get back to you as soon as possible.</p>
        
        <p>Here's a copy of your message:</p>
        
        <blockquote style="background-color: #f9fafb; padding: 15px; border-left: 4px solid #2563eb; margin: 20px 0;">
            {!! nl2br(e($submission->message)) !!}
        </blockquote>
        
        <p>We appreciate your interest and look forward to connecting with you.</p>
        
        <p>Best regards,<br>{{ $client->name ?? 'The Team' }}</p>
    </div>
    
    <div class="footer">
        <p>This is an automated confirmation email. Please do not reply directly to this message.</p>
    </div>
</body>
</html>
