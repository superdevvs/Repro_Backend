@extends('emails.layouts.master')

@section('title', 'Weekly Payout Approvals Digest')
@section('preheader', 'Weekly photographer and rep payout totals are ready for review.')

@section('hero')
    <div class="eyebrow">Payout Digest</div>
    <h1 class="hero-title">Approvals digest for {{ $rangeStart->format('M d') }} - {{ $rangeEnd->format('M d, Y') }}</h1>
    <p class="hero-copy">This digest rolls up photographer and sales rep payout totals so accounting can review and release payments with confidence.</p>
@endsection

@section('content')
    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">Review in Dashboard</a>
    </div>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Photographers</div>
            <div class="section-title">Photographer payout totals</div>
            <table class="line-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <th>Name</th>
                    <th>Shoots</th>
                    <th style="text-align:right;">Gross</th>
                </tr>
                @foreach($photographers as $row)
                    <tr>
                        <td><span class="line-name">{{ $row['name'] }}</span></td>
                        <td><span class="line-meta" style="margin-top:0;">{{ $row['shoot_count'] }}</span></td>
                        <td class="amount-cell">{{ '$' . number_format($row['gross_total'], 2) }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="divider"></div>
            <div class="section-copy" style="margin-top:0;"><strong>Total photographer payout:</strong> {{ '$' . number_format($totalPhotographerPayout, 2) }}</div>
        </div>
    </div>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Sales Reps</div>
            <div class="section-title">Rep commission totals</div>
            <table class="line-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <th>Name</th>
                    <th>Shoots</th>
                    <th style="text-align:right;">Gross</th>
                    <th style="text-align:right;">Commission</th>
                </tr>
                @foreach($reps as $row)
                    <tr>
                        <td><span class="line-name">{{ $row['name'] }}</span></td>
                        <td><span class="line-meta" style="margin-top:0;">{{ $row['shoot_count'] }}</span></td>
                        <td class="amount-cell">{{ '$' . number_format($row['gross_total'], 2) }}</td>
                        <td class="amount-cell">{{ '$' . number_format($row['commission_total'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
            </table>
            <div class="divider"></div>
            <div class="section-copy" style="margin-top:0;"><strong>Total rep commission:</strong> {{ '$' . number_format($totalRepPayout ?? 0, 2) }}</div>
        </div>
    </div>

    <div class="callout">
        <div class="callout-title">Accounting follow-up</div>
        <p class="callout-copy">Please review these totals and approve any final adjustments so accounting can release payments on schedule.</p>
    </div>
@endsection
