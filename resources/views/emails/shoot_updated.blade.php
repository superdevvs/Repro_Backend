@extends('emails.layouts.master')
@section('title', 'Scheduled Photo Shoot Updated')
@section('content')
    <p>Hi {{ $user->first_name }}!</p>

    @if(!empty($isPhotographer))
    <p>
        A shoot you are assigned to has been updated.
    </p>
    @else
    <p>
        One of your scheduled photo shoots has been updated.
    </p>
    @endif

    @if(isset($changesSummary) && !empty($changesSummary))
    <p>
        <strong>What Changed:</strong><br>
        {!! nl2br(e($changesSummary)) !!}
    </p>
    @endif

    <p>
        <strong>Current Shoot Details:</strong><br>
        Location: {{ $shoot->location }}<br>
        Scheduled Date: {{ $shoot->date }}<br>
        @if(!empty($isPhotographer))
        Client: {{ $shoot->client_name ?? 'N/A' }}<br>
        @else
        Photographer: {{ $shoot->photographer }}<br>
        @endif
        Services:
        @if(count($shoot->packages) > 0)
            @foreach($shoot->packages as $package)
                {{ $package['name'] }}{{ isset($package['price']) && $package['price'] > 0 ? ' — $' . number_format($package['price'], 2) : '' }}@if(!$loop->last), @endif
            @endforeach
        @else
            N/A
        @endif
        <br>
        Total: ${{ number_format($shoot->grand_total, 2) }}
    </p>

    @if($shoot->notes)
    <p>
        <strong>Notes:</strong><br>
        {{ $shoot->notes }}
    </p>
    @endif

    <p>
        Visit <a href="https://reprodashboard.com">https://reprodashboard.com</a> to manage your shoots.
    </p>

    @if(!empty($isPhotographer))
    <p>
        Please review the updated details and contact the office if you have any questions or conflicts.
    </p>
    @else
    <p>
        To ensure a smooth shoot process, please have the property ready. 
        Here is a link to getting your property ready for the shoot: 
        <a href="https://reprophotos.com/tips-to-get-your-property-camera-ready/">
            Tips to Get Your Property Camera Ready
        </a>
    </p>

    <p>
        <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, 
        a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. 
        We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
    </p>
    @endif

    <p>
        If you have any questions about this photo shoot please feel free to reply to this email, 
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

    @if(empty($isPhotographer))
    <p>
        We would love your feedback: 
        <a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews" target="_blank">
            Post a review on Google
        </a>.
    </p>
    @endif
@endsection
