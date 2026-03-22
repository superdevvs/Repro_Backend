@extends('emails.layouts.master')

@section('title', 'Weekly Payout Recap')
@section('preheader', 'Your weekly payout recap is ready.')

@section('hero')
    <div class="eyebrow">Payout Recap</div>
    <h1 class="hero-title">{{ $rangeStart->format('M d') }} - {{ $rangeEnd->format('M d, Y') }}</h1>
    <p class="hero-copy">This weekly summary shows completed shoot volume, gross totals, and projected payout details for your role.</p>
@endsection

@section('content')
    <p class="intro">{{ $recipientName }}, <strong>here is your payout recap.</strong></p>

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">Open Dashboard</a>
    </div>

    <table class="stats-row" role="presentation">
        <tr>
            <td><div class="stat-card"><div class="stat-label">Role</div><div class="stat-copy">{{ ucfirst($audience) }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Completed Shoots</div><div class="stat-value">{{ $summary['shoot_count'] ?? 0 }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Gross Total</div><div class="stat-value">{{ '$' . number_format($summary['gross_total'] ?? 0, 2) }}</div></div></td>
        </tr>
    </table>

    <table class="stats-row" role="presentation">
        <tr>
            <td><div class="stat-card"><div class="stat-label">Average Shoot Value</div><div class="stat-copy">{{ '$' . number_format($summary['average_value'] ?? 0, 2) }}</div></div></td>
            @if(!empty($summary['commission_rate']))
                <td><div class="stat-card"><div class="stat-label">Commission Rate</div><div class="stat-copy">{{ $summary['commission_rate'] }}%</div></div></td>
                <td><div class="stat-card"><div class="stat-label">Projected Commission</div><div class="stat-copy">{{ '$' . number_format($summary['commission_total'] ?? 0, 2) }}</div></div></td>
            @endif
        </tr>
    </table>

    <div class="callout">
        <div class="callout-title">Need a correction?</div>
        <p class="callout-copy">If anything in this recap looks off, reply to this email so our accounting team can review it before payouts are finalized.</p>
    </div>
@endsection
