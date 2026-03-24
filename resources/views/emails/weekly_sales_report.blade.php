@extends('emails.layouts.master')

@section('title', 'Weekly Sales Report - ' . $weekLabel)
@section('preheader', 'Your weekly sales summary is ready.')

@section('hero')
    <div class="eyebrow">Weekly Sales Report</div>
    <h1 class="hero-title">{{ $weekLabel }}</h1>
    <p class="hero-copy">Your weekly sales snapshot includes top-line performance, client activity, and the highest-value shoots from the period.</p>
@endsection

@section('content')
<p class="intro"><strong>Here is your weekly sales report.</strong></p>

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">Open Dashboard</a>
    </div>

    <table class="stats-row" role="presentation">
        <tr>
            <td><div class="stat-card"><div class="stat-label">Total Shoots</div><div class="stat-value">{{ $report['summary']['total_shoots'] }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Completion Rate</div><div class="stat-value">{{ $report['summary']['completion_rate'] }}%</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Revenue</div><div class="stat-value">{{ '$' . number_format($report['summary']['total_revenue'], 2) }}</div></div></td>
        </tr>
    </table>

    <table class="stats-row" role="presentation">
        <tr>
            <td><div class="stat-card"><div class="stat-label">Completed</div><div class="stat-copy">{{ $report['summary']['completed_shoots'] }} shoots</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Total Paid</div><div class="stat-copy">{{ '$' . number_format($report['summary']['total_paid'], 2) }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-copy">{{ '$' . number_format($report['summary']['outstanding_balance'], 2) }}</div></div></td>
        </tr>
    </table>

    @if(count($report['clients']) > 0)
        <div class="section-card">
            <div class="section-pad">
                <div class="section-kicker">Clients</div>
                <div class="section-title">Client activity</div>
                <table class="line-table" role="presentation" style="margin-top:12px;">
                    <tr>
                        <th>Client</th>
                        <th>Shoots</th>
                        <th style="text-align:right;">Revenue</th>
                    </tr>
                    @foreach($report['clients'] as $client)
                        <tr>
                            <td>
                                <span class="line-name">{{ $client['client_name'] }}</span>
                                <span class="line-meta">Paid: {{ '$' . number_format($client['total_paid'], 2) }}</span>
                            </td>
                            <td><span class="line-meta" style="margin-top:0;">{{ $client['shoot_count'] }}</span></td>
                            <td class="amount-cell">{{ '$' . number_format($client['total_revenue'], 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    @if(count($report['top_shoots']) > 0)
        <div class="section-card">
            <div class="section-pad">
                <div class="section-kicker">Top Shoots</div>
                <div class="section-title">Highest revenue shoots</div>
                <table class="line-table" role="presentation" style="margin-top:12px;">
                    <tr>
                        <th>Shoot</th>
                        <th>Date</th>
                        <th style="text-align:right;">Revenue</th>
                    </tr>
                    @foreach($report['top_shoots'] as $shoot)
                        <tr>
                            <td>
                                <span class="line-name">#{{ $shoot['shoot_id'] }} | {{ $shoot['client_name'] }}</span>
                                <span class="line-meta">{{ $shoot['workflow_status'] }}</span>
                            </td>
                            <td><span class="line-meta" style="margin-top:0;">{{ $shoot['scheduled_date'] ?? 'N/A' }}</span></td>
                            <td class="amount-cell">{{ '$' . number_format($shoot['total_quote'], 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif
@endsection

@section('footer_note')
    For deeper drill-down, continue the review inside your dashboard.
@endsection
