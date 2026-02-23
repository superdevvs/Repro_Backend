@extends('emails.layouts.master')
@section('title', 'Thank You for Your Payment!')
@section('content')
    <p>Hi {{ $user->first_name }}!</p>

    <p>Thank you for paying for your photo shoot!</p>

    <p>
        Location: {{ $shoot->location }}<br>
        Payment Date: {{ $payment->created_at }}<br>
        Payment Amount: ${{ number_format($payment->amount, 2) }}
    </p>

    <p>
        Scheduled Date: {{ $shoot->date }}<br>
        Photographer: {{ $shoot->photographer }}<br>
        Services: @foreach($shoot->packages as $package){{ $package['name'] }}@if(!$loop->last), @endif @endforeach
    </p>

    @if($shoot->notes)
    <p>
        <strong>Notes:</strong><br>
        {{ $shoot->notes }}
    </p>
    @endif

    <p>
        Once your photos are completed you will receive a Summary email 
        if you have photo packages ready for download.
    </p>

    <p>
        If you have any questions about this photo shoot please reply to this email, 
        or email <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> directly.
    </p>

    <p>Thank you!</p>

    <p>
        Customer Service Team<br>
        202-868-1663<br>
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a><br>
        <a href="https://reprophotos.com">https://reprophotos.com</a><br>
        Dashboard: <a href="https://reprodashboard.com">https://reprodashboard.com</a>
    </p>

    <p>
        We would love your feedback: 
        <a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews" target="_blank">
            Post a review on Google
        </a>.
    </p>
@endsection
