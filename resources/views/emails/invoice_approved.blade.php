@extends('emails.layouts.master')

@section('title', 'Invoice Approved')
@section('preheader', 'Your invoice has been approved.')

@section('hero')
    <div class="eyebrow">Invoice Approved</div>
    <h1 class="hero-title">Your invoice has been approved.</h1>
    <p class="hero-copy">The invoice for this period has passed review and is now cleared for the next payout step.</p>
@endsection

@section('content')
    <p class="intro">Hello {{ $photographer->name }}, <strong>your invoice for {{ $period }} has been approved.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Approval Details</div>
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
                    <td class="detail-label">Approved At</td>
                    <td class="detail-value">{{ $invoice->approved_at ? $invoice->approved_at->format('M j, Y g:i A') : 'N/A' }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">View Invoice</a>
    </div>
@endsection
