@extends('emails.layouts.master')
@section('title', 'Weekly Payout Approvals Digest')
@section('content')
    <h3>Weekly payout approvals digest</h3>

    <p>Period: <strong>{{ $rangeStart->format('M d') }} – {{ $rangeEnd->format('M d, Y') }}</strong></p>

    <table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:16px;">
        <tr><th style="text-align:left;">Photographers</th><th>Shoots</th><th style="text-align:right;">Gross</th></tr>
        @foreach($photographers as $row)
        <tr><td>{{ $row['name'] }}</td><td style="text-align:center;">{{ $row['shoot_count'] }}</td><td style="text-align:right;">${{ number_format($row['gross_total'], 2) }}</td></tr>
        @endforeach
    </table>

    <p><strong>Total photographer payout:</strong> ${{ number_format($totalPhotographerPayout, 2) }}</p>

    <table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:16px;">
        <tr><th style="text-align:left;">Sales reps</th><th>Shoots</th><th style="text-align:right;">Gross</th><th style="text-align:right;">Commission</th></tr>
        @foreach($reps as $row)
        <tr><td>{{ $row['name'] }}</td><td style="text-align:center;">{{ $row['shoot_count'] }}</td><td style="text-align:right;">${{ number_format($row['gross_total'], 2) }}</td><td style="text-align:right;">${{ number_format($row['commission_total'] ?? 0, 2) }}</td></tr>
        @endforeach
    </table>

    <p><strong>Total rep commission:</strong> ${{ number_format($totalRepPayout ?? 0, 2) }}</p>

    <p>Please review and approve so accounting can release payments. Let us know if anything needs adjustment.</p>

    <p>Thanks!<br>— Ops Bot</p>
@endsection

