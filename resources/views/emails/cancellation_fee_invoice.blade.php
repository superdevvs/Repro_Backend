<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Fee Invoice</title>
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
            margin-bottom: 30px;
        }
        .header h1 {
            color: #1f2937;
            margin: 0;
            font-size: 24px;
        }
        .invoice-details {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .invoice-details p {
            margin: 8px 0;
        }
        .amount {
            font-size: 28px;
            font-weight: bold;
            color: #dc2626;
            text-align: center;
            padding: 20px;
            background-color: #fef2f2;
            border-radius: 8px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 14px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Cancellation Fee Invoice</h1>
    </div>

    <p>Hello {{ $client->name }},</p>

    <p>A cancellation fee has been applied to your account for the following property:</p>

    <div class="invoice-details">
        <p><strong>Property Address:</strong> {{ $address }}</p>
        <p><strong>Invoice Number:</strong> {{ $invoice->invoice_number }}</p>
        <p><strong>Issue Date:</strong> {{ $invoice->issue_date->format('F j, Y') }}</p>
        <p><strong>Due Date:</strong> {{ $invoice->due_date->format('F j, Y') }}</p>
    </div>

    <div class="amount">
        Amount Due: ${{ number_format($invoice->total, 2) }}
    </div>

    <p>This fee has been applied in accordance with our cancellation policy. Please make payment by the due date to avoid any additional charges.</p>

    <p>If you have any questions about this invoice, please contact our support team.</p>

    <div class="footer">
        <p>Thank you for your business.</p>
        <p>REPRO HQ</p>
    </div>
</body>
</html>
