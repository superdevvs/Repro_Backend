<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice Rejected</title>
</head>
<body>
    <p>Subject: Invoice Rejected - {{ $period }}</p>

    <p>Hello, {{ $photographer->name }}!</p>

    <p>Your invoice for {{ $period }} has been rejected.</p>

    <h3>Invoice Details</h3>
    <ul>
        <li><strong>Invoice Number:</strong> {{ $invoice->invoice_number ?? 'N/A' }}</li>
        <li><strong>Period:</strong> {{ $period }}</li>
        <li><strong>Total Amount:</strong> ${{ number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</li>
        <li><strong>Rejected At:</strong> {{ $invoice->rejected_at ? $invoice->rejected_at->format('M j, Y g:i A') : 'N/A' }}</li>
        @if($invoice->rejection_reason)
        <li><strong>Rejection Reason:</strong> {{ $invoice->rejection_reason }}</li>
        @endif
    </ul>

    <p>
        You can review the rejection reason and make necessary changes by logging into your dashboard at 
        <a href="https://pro.reprophotos.com">https://pro.reprophotos.com</a>
    </p>

    <p>
        If you have any questions, please contact us at 
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a>
    </p>

    <p>
        Customer Service Team <br>
        R/E Pro Photos <br>
        202-868-1663 <br>
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a>
    </p>
</body>
</html>


