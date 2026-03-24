@extends('emails.layouts.master')

@section('title', 'Invoice Requires Approval')
@section('preheader', 'A photographer-modified invoice is waiting for admin review.')

@section('hero')
    <div class="eyebrow">Approval Needed</div>
    <h1 class="hero-title">An invoice requires admin review.</h1>
    <p class="hero-copy">A photographer updated an invoice for this payout period. Review the modified details below and approve or reject it in the dashboard.</p>
@endsection

@section('content')
<p class="intro"><strong>There is an invoice waiting for your decision.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Invoice Summary</div>
            <div class="section-title">{{ $invoice->invoice_number ?? 'N/A' }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Photographer</td>
                    <td class="detail-value">{{ $photographer->name }}<span class="detail-subvalue">{{ $photographer->email }}</span></td>
                </tr>
                <tr>
                    <td class="detail-label">Period</td>
                    <td class="detail-value">{{ $period }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Total</td>
                    <td class="detail-value">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Modified At</td>
                    <td class="detail-value">{{ $invoice->modified_at ? $invoice->modified_at->format('M j, Y g:i A') : 'N/A' }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if($invoice->modification_notes)
        <div class="note-card">
            <div class="note-title">Modification Notes</div>
            <div class="body-copy" style="margin-top:0;">{{ $invoice->modification_notes }}</div>
        </div>
    @endif

    <div class="button-row">
        <a href="https://reprodashboard.com" class="button">Review Invoice</a>
    </div>
@endsection

@section('footer_note')
    Approving or rejecting the invoice in the dashboard will keep accounting and the photographer aligned.
@endsection
