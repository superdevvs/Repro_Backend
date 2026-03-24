@extends('emails.layouts.master')

@section('title', 'Weekly Invoice - ' . $period)
@section('preheader', 'Your weekly invoice is ready to review.')

@section('hero')
    <div class="eyebrow">Weekly Invoicing</div>
    <h1 class="hero-title">Your invoice for {{ $period }} is ready.</h1>
    <p class="hero-copy">Review the payout window, confirm the line items included this week, and handle any follow-up before approval moves forward.</p>
@endsection

@section('content')
    @php
        $recipientModel = $recipient ?? $photographer;
        $recipientLabel = $recipientRole ?? 'photographer';
    @endphp

<p class="intro"><strong>Your weekly {{ $recipientLabel }} invoice has been generated.</strong></p>

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button button-large">Review Weekly Invoice</a>
    </div>

    <table class="stats-row" role="presentation">
        <tr>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Invoice Number</div>
                    <div class="stat-copy">{{ $invoice->invoice_number ?? 'N/A' }}</div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Status</div>
                    <div class="stat-copy">{{ ucfirst($invoice->status) }}</div>
                </div>
            </td>
            <td>
                <div class="stat-card">
                    <div class="stat-label">Total</div>
                    <div class="stat-value">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</div>
                </div>
            </td>
        </tr>
    </table>

    @if($invoice->items && $invoice->items->count() > 0)
        <div class="section-card">
            <div class="section-pad">
                <div class="section-kicker">Invoice Items</div>
                <div class="section-title">Line item breakdown</div>
                <table class="line-table" role="presentation" style="margin-top:12px;">
                    <tr>
                        <th>Description</th>
                        <th>Type</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                    @foreach($invoice->items as $item)
                        <tr>
                            <td><span class="line-name">{{ $item->description }}</span></td>
                            <td><span class="line-meta" style="margin-top:0;">{{ ucfirst($item->type) }}</span></td>
                            <td class="amount-cell">{{ '$' . number_format($item->total_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    @endif

    <div class="callout">
        <div class="callout-title">Next step</div>
        <p class="callout-copy">Open the dashboard to review this invoice, add any missing expenses, or flag an issue if something needs adjustment before approval.</p>
    </div>
@endsection

@section('footer_note')
    Changes made to the invoice after generation may trigger a fresh approval review.
@endsection
