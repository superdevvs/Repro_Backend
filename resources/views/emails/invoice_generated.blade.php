<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Weekly Invoice - {{ $period }}</title>
</head>
<body>
    <p>Hello, {{ $photographer->name }}!</p>

    <p>Your weekly invoice for {{ $period }} has been generated.</p>

    <h3>Invoice Details</h3>
    <ul>
        <li><strong>Invoice Number:</strong> {{ $invoice->invoice_number ?? 'N/A' }}</li>
        <li><strong>Period:</strong> {{ $period }}</li>
        <li><strong>Total Amount:</strong> ${{ number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</li>
        <li><strong>Status:</strong> {{ ucfirst($invoice->status) }}</li>
    </ul>

    @if($invoice->items && $invoice->items->count() > 0)
    <h3>Invoice Items</h3>
    <table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <th>Description</th>
            <th>Type</th>
            <th>Amount</th>
        </tr>
        @foreach($invoice->items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td>{{ ucfirst($item->type) }}</td>
            <td>${{ number_format($item->total_amount, 2) }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    <p>
        You can review and manage your invoice by logging into your dashboard at 
        <a href="https://reprodashboard.com">https://reprodashboard.com</a>
    </p>

    <p>
        You can add additional expenses or reject the invoice if needed. 
        If you make changes, the invoice will require admin approval.
    </p>

    <p>
        Customer Service Team<br>
        202-868-1663<br>
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a>
    </p>
</body>
</html>


