@extends('emails.layouts.master')

@section('title', 'Weekly Sales Report - ' . $weekLabel)
@section('preheader', 'Your weekly sales summary is ready.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Weekly Sales Report</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">{{ $weekLabel }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Your weekly sales snapshot includes top-line performance, client activity, and the highest-value shoots from the period.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Here is your weekly sales report.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="https://reprodashboard.com" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Total Shoots</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ $report['summary']['total_shoots'] }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Completion Rate</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ $report['summary']['completion_rate'] }}%</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Revenue</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ '$' . number_format($report['summary']['total_revenue'], 2) }}</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Completed</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ $report['summary']['completed_shoots'] }} shoots</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Total Paid</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ '$' . number_format($report['summary']['total_paid'], 2) }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Outstanding</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ '$' . number_format($report['summary']['outstanding_balance'], 2) }}</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    @if(count($report['clients']) > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Clients</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Client activity</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Client</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Shoots</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Revenue</td>
                    </tr>
                    @foreach($report['clients'] as $client)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;">
                            <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $client['client_name'] }}</span>
                            <span class="dark-muted" style="display:block; margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">Paid: {{ '$' . number_format($client['total_paid'], 2) }}</span>
                        </td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $client['shoot_count'] }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($client['total_revenue'], 2) }}</td>
                    </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
    @endif

    @if(count($report['top_shoots']) > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Top Shoots</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Highest revenue shoots</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Shoot</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Date</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Revenue</td>
                    </tr>
                    @foreach($report['top_shoots'] as $shoot)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;">
                            <span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">#{{ $shoot['shoot_id'] }} | {{ $shoot['client_name'] }}</span>
                            <span class="dark-muted" style="display:block; margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">{{ $shoot['workflow_status'] }}</span>
                        </td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $shoot['scheduled_date'] ?? 'N/A' }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($shoot['total_quote'], 2) }}</td>
                    </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
    @endif
@endsection

@section('footer_note')
    For deeper drill-down, continue the review inside your dashboard.
@endsection
