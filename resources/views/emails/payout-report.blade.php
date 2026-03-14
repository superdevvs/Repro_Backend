@extends('emails.layouts.master')
@section('title', 'Weekly Payout Recap')
@section('content')
    <p>{{ $recipientName }},</p>

    <p>Here is your payout recap for <strong>{{ $rangeStart->format('M d') }} – {{ $rangeEnd->format('M d, Y') }}</strong>.</p>

    <div style="background-color:#f9fafb;padding:16px;border-radius:8px;margin:16px 0;">
        <p style="margin:4px 0;">Role: <strong>{{ ucfirst($audience) }}</strong></p>
        <p style="margin:4px 0;">Completed shoots: <strong>{{ $summary['shoot_count'] ?? 0 }}</strong></p>
        <p style="margin:4px 0;">Gross total: <strong>${{ number_format($summary['gross_total'] ?? 0, 2) }}</strong></p>
        <p style="margin:4px 0;">Average shoot value: <strong>${{ number_format($summary['average_value'] ?? 0, 2) }}</strong></p>
        @if(!empty($summary['commission_rate']))
        <p style="margin:4px 0;">Commission rate: <strong>{{ $summary['commission_rate'] }}%</strong></p>
        <p style="margin:4px 0;">Projected commission: <strong>${{ number_format($summary['commission_total'] ?? 0, 2) }}</strong></p>
        @endif
    </div>

    <p>If anything looks off, reply to this email so our accounting team can help before payouts go out.</p>

    <p>Thanks for keeping our clients happy!<br>— Repro Photos Ops</p>
@endsection

