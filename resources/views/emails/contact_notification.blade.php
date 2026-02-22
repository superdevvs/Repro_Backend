<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Contact Form Submission</title>
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
            background-color: #2563eb;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 0 0 8px 8px;
        }
        .field {
            margin-bottom: 15px;
        }
        .field-label {
            font-weight: bold;
            color: #374151;
            font-size: 14px;
        }
        .field-value {
            color: #1f2937;
            margin-top: 4px;
        }
        .message-box {
            background-color: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            margin-top: 8px;
        }
        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0; font-size: 20px;">New Contact Form Submission</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $client->name }},</p>
        
        <p>You have received a new message through your portfolio contact form.</p>
        
        <div class="field">
            <div class="field-label">From:</div>
            <div class="field-value">{{ $submission->sender_name }}</div>
        </div>
        
        <div class="field">
            <div class="field-label">Email:</div>
            <div class="field-value">
                <a href="mailto:{{ $submission->sender_email }}">{{ $submission->sender_email }}</a>
            </div>
        </div>
        
        @if($submission->sender_phone)
        <div class="field">
            <div class="field-label">Phone:</div>
            <div class="field-value">{{ $submission->sender_phone }}</div>
        </div>
        @endif
        
        <div class="field">
            <div class="field-label">Message:</div>
            <div class="message-box">
                {!! nl2br(e($submission->message)) !!}
            </div>
        </div>
        
        <p style="margin-top: 20px; font-size: 14px; color: #6b7280;">
            Received on {{ $submission->created_at->format('F j, Y \a\t g:i A') }}
        </p>
    </div>
    
    <div class="footer">
        <p>This message was sent via your REPRO HQ portfolio.</p>
    </div>
</body>
</html>
