<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Weekly Sales Report - {{ $weekLabel }}</title>
</head>
<body>
    <p>Subject: Weekly Sales Report - {{ $weekLabel }}</p>

    <p>Hello, {{ $salesRep->name }}!</p>

    <p>Here is your weekly sales report for {{ $weekLabel }}:</p>

    <h3>Summary</h3>
    <ul>
        <li><strong>Total Shoots:</strong> {{ $report['summary']['total_shoots'] }}</li>
        <li><strong>Completed Shoots:</strong> {{ $report['summary']['completed_shoots'] }}</li>
        <li><strong>Completion Rate:</strong> {{ $report['summary']['completion_rate'] }}%</li>
        <li><strong>Total Revenue:</strong> ${{ number_format($report['summary']['total_revenue'], 2) }}</li>
        <li><strong>Total Paid:</strong> ${{ number_format($report['summary']['total_paid'], 2) }}</li>
        <li><strong>Outstanding Balance:</strong> ${{ number_format($report['summary']['outstanding_balance'], 2) }}</li>
        <li><strong>Average Shoot Value:</strong> ${{ number_format($report['summary']['average_shoot_value'], 2) }}</li>
    </ul>

    @if(count($report['clients']) > 0)
    <h3>Clients ({{ count($report['clients']) }})</h3>
    <table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <th>Client Name</th>
            <th>Shoot Count</th>
            <th>Total Revenue</th>
            <th>Total Paid</th>
        </tr>
        @foreach($report['clients'] as $client)
        <tr>
            <td>{{ $client['client_name'] }}</td>
            <td>{{ $client['shoot_count'] }}</td>
            <td>${{ number_format($client['total_revenue'], 2) }}</td>
            <td>${{ number_format($client['total_paid'], 2) }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    @if(count($report['top_shoots']) > 0)
    <h3>Top Shoots by Revenue</h3>
    <table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <th>Shoot ID</th>
            <th>Client</th>
            <th>Date</th>
            <th>Revenue</th>
            <th>Status</th>
        </tr>
        @foreach($report['top_shoots'] as $shoot)
        <tr>
            <td>#{{ $shoot['shoot_id'] }}</td>
            <td>{{ $shoot['client_name'] }}</td>
            <td>{{ $shoot['scheduled_date'] ?? 'N/A' }}</td>
            <td>${{ number_format($shoot['total_quote'], 2) }}</td>
            <td>{{ $shoot['workflow_status'] }}</td>
        </tr>
        @endforeach
    </table>
    @endif

    <p>
        For more details, please log in to your dashboard at 
        <a href="https://pro.reprophotos.com">https://pro.reprophotos.com</a>
    </p>

    <p>
        Customer Service Team <br>
        R/E Pro Photos <br>
        202-868-1663 <br>
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> <br>
        <a href="https://reprophotos.com">https://reprophotos.com</a>
    </p>
</body>
</html>


