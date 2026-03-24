@extends('emails.layouts.master')

@section('title', 'Invoice Rejected')
@section('preheader', 'Your invoice needs revisions before approval.')

@section('hero')
    <div class="eyebrow">Invoice Rejected</div>
    <h1 class="hero-title">Your invoice needs a revision.</h1>
    <p class="hero-copy">The invoice for this period was rejected during review. Use the reason below to update it and resubmit through the dashboard.</p>
@endsection

@section('content')
<p class="intro"><strong>Your invoice for {{ $period }} was rejected.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Invoice Review</div>
            <div class="section-title">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Invoice Number</td>
                    <td class="detail-value">{{ $invoice->invoice_number ?? 'N/A' }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Period</td>
                    <td class="detail-value">{{ $period }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Rejected At</td>
                    <td class="detail-value">{{ $invoice->rejected_at ? $invoice->rejected_at->format('M j, Y g:i A') : 'N/A' }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if($invoice->rejection_reason)
        <div class="callout callout-danger">
            <div class="callout-title">Rejection reason</div>
            <p class="callout-copy">{{ $invoice->rejection_reason }}</p>
        </div>
    @endif

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">Review and Update</a>
    </div>
@endsection

@section('footer_note')
    Once corrected, the invoice can be resubmitted for approval.
@endsection
