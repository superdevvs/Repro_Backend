@extends('emails.layouts.master')

@section('title', 'Thank You for Your Payment!')
@section('preheader', 'Your payment for this shoot has been received.')

@section('hero')
    <div class="eyebrow">Payment Received</div>
    <h1 class="hero-title">Thank you. Your payment is in.</h1>
    <p class="hero-copy">We have recorded your payment for the shoot below. This receipt summarizes the payment and the booked services tied to it.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>thank you for taking care of the payment.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Receipt</div>
            <div class="section-title">{{ '$' . number_format($payment->amount, 2) }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Payment date</td>
                    <td class="detail-value">{{ $payment->created_at }}</td>
                </tr>
                <tr>
                    <td class="detail-label">Method</td>
                    <td class="detail-value">{{ $payment->payment_method ?? 'Card' }}</td>
                </tr>
                @if(!empty($payment->transaction_id))
                    <tr>
                        <td class="detail-label">Transaction ID</td>
                        <td class="detail-value">{{ $payment->transaction_id }}</td>
                    </tr>
                @endif
            </table>
        </div>
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])

    <div class="callout callout-success">
        <div class="callout-title">What happens next</div>
        <p class="callout-copy">Your shoot will continue moving through production. Once the media package is complete, you will receive another email letting you know the files are ready.</p>
    </div>
@endsection

@section('footer_note')
    Keep this confirmation for your accounting records.
@endsection
