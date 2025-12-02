<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice Requires Approval</title>
</head>
<body>
    <p>Subject: Invoice Requires Approval - {{ $photographer->name }} - {{ $period }}</p>

    <p>Hello, {{ $admin->name }}!</p>

    <p>An invoice has been modified by a photographer and requires your approval.</p>

    <h3>Invoice Details</h3>
    <ul>
        <li><strong>Photographer:</strong> {{ $photographer->name }} ({{ $photographer->email }})</li>
        <li><strong>Invoice Number:</strong> {{ $invoice->invoice_number ?? 'N/A' }}</li>
        <li><strong>Period:</strong> {{ $period }}</li>
        <li><strong>Total Amount:</strong> ${{ number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</li>
        <li><strong>Modified At:</strong> {{ $invoice->modified_at ? $invoice->modified_at->format('M j, Y g:i A') : 'N/A' }}</li>
        @if($invoice->modification_notes)
        <li><strong>Modification Notes:</strong> {{ $invoice->modification_notes }}</li>
        @endif
    </ul>

    <p>
        Please review and approve or reject this invoice by logging into the admin dashboard at 
        <a href="https://pro.reprophotos.com">https://pro.reprophotos.com</a>
    </p>

    <p>
        Customer Service Team <br>
        R/E Pro Photos
    </p>
</body>
</html>


