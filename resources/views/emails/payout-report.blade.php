@extends('emails.layouts.master')

@section('title', 'Weekly Payout Recap')
@section('preheader', 'Your weekly payout recap is ready.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Payout Recap</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:32px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">{{ $rangeStart->format('M d') }} - {{ $rangeEnd->format('M d, Y') }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">This weekly summary shows completed shoot volume, gross totals, and projected payout details for your role.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Here is your payout recap.</strong></p>

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
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Role</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ ucfirst($audience) }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Completed Shoots</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ $summary['shoot_count'] ?? 0 }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Gross Total</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ '$' . number_format($summary['gross_total'] ?? 0, 2) }}</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Average Shoot Value</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ '$' . number_format($summary['average_value'] ?? 0, 2) }}</p>
                </td></tr></table>
            </td>
            @if(!empty($summary['commission_rate']))
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Commission Rate</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ $summary['commission_rate'] }}%</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Projected Commission</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ '$' . number_format($summary['commission_total'] ?? 0, 2) }}</p>
                </td></tr></table>
            </td>
            @endif
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Need a correction?</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">If anything in this recap looks off, reply to this email so our accounting team can review it before payouts are finalized.</p>
            </td>
        </tr>
    </table>
@endsection
