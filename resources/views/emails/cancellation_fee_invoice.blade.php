@extends('emails.layouts.master')

@section('title', 'Cancellation Fee Invoice')
@section('preheader', 'A cancellation fee invoice has been issued for a property appointment.')

@section('hero')
    <div class="eyebrow">Cancellation Fee</div>
    <h1 class="hero-title">A cancellation fee invoice has been issued.</h1>
    <p class="hero-copy">This invoice reflects a cancellation or on-site appointment issue covered by our cancellation policy. The billing details are listed below.</p>
@endsection

@section('content')
    <p class="intro">Hello {{ $client->name }}, <strong>a cancellation fee has been added to your account.</strong></p>

    <table class="stats-row" role="presentation">
        <tr>
            <td><div class="stat-card"><div class="stat-label">Amount Due</div><div class="stat-value">{{ '$' . number_format($invoice->total, 2) }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Issue Date</div><div class="stat-copy">{{ $invoice->issue_date->format('F j, Y') }}</div></div></td>
            <td><div class="stat-card"><div class="stat-label">Due Date</div><div class="stat-copy">{{ $invoice->due_date->format('F j, Y') }}</div></div></td>
        </tr>
    </table>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Invoice Details</div>
            <div class="section-title">{{ $invoice->invoice_number }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Property</td>
                    <td class="detail-value">{{ $address }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Invoice Number</td>
                    <td class="detail-value">{{ $invoice->invoice_number }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="callout callout-warning">
        <div class="callout-title">Payment reminder</div>
        <p class="callout-copy">Please pay the invoice by the due date to avoid additional collection friction. If you have questions about the fee, reply to this email and our team will help.</p>
    </div>
@endsection

@section('footer_note')
    This fee is issued according to the current cancellation policy on your account.
@endsection
