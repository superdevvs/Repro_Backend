@extends('emails.layouts.master')

@section('title', 'Weekly Payout Approvals Digest')
@section('preheader', 'Weekly payout totals for photographers, editors, and reps are ready for review.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Payout Digest</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Approvals digest for {{ $rangeStart->format('M d') }} - {{ $rangeEnd->format('M d, Y') }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">This digest rolls up photographer, editor, and sales rep payout totals so accounting can review and release payments with confidence.</p>
@endsection

@section('content')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="https://reprodashboard.com" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Review in Dashboard</a>
            </td>
        </tr>
    </table>

    {{-- Editors section --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Editors</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Editor earnings totals</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Name</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Shoots</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Services</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Gross</td>
                    </tr>
                    @foreach(($editors ?? []) as $row)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;"><span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $row['name'] }}</span></td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $row['shoot_count'] }}</td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $row['service_count'] }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($row['gross_total'], 2) }}</td>
                    </tr>
                    @endforeach
                </table>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 0;">
                    <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
                </table>
                <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;"><strong class="dark-strong" style="color:#071223;">Total editor payout:</strong> {{ '$' . number_format($totalEditorPayout ?? 0, 2) }}</p>
            </td>
        </tr>
    </table>

    {{-- Photographers section --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Photographers</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Photographer payout totals</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Name</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Shoots</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Gross</td>
                    </tr>
                    @foreach($photographers as $row)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;"><span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $row['name'] }}</span></td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $row['shoot_count'] }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($row['gross_total'], 2) }}</td>
                    </tr>
                    @endforeach
                </table>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 0;">
                    <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
                </table>
                <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;"><strong class="dark-strong" style="color:#071223;">Total photographer payout:</strong> {{ '$' . number_format($totalPhotographerPayout, 2) }}</p>
            </td>
        </tr>
    </table>

    {{-- Sales Reps section --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Sales Reps</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Rep commission totals</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Name</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Shoots</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Gross</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Commission</td>
                    </tr>
                    @foreach($reps as $row)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;"><span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $row['name'] }}</span></td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ $row['shoot_count'] }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($row['gross_total'], 2) }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($row['commission_total'] ?? 0, 2) }}</td>
                    </tr>
                    @endforeach
                </table>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 0;">
                    <tr><td class="divider-bg" style="height:1px; background-color:#edf2f7; font-size:0; line-height:0;">&nbsp;</td></tr>
                </table>
                <p class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;"><strong class="dark-strong" style="color:#071223;">Total rep commission:</strong> {{ '$' . number_format($totalRepPayout ?? 0, 2) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Accounting follow-up</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Please review these totals and approve any final adjustments so accounting can release payments on schedule.</p>
            </td>
        </tr>
    </table>
@endsection
