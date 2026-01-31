<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Shoot Scheduled</title>
</head>
<body>
    <p>Hello, {{ $user->name }}!</p>

    <p>A new photo shoot has been scheduled under your account!</p>

    <p>You can find the shoot listed under Scheduled Shoots after logging into 
        <a href="https://reprodashboard.com">https://reprodashboard.com</a>
    </p>

    <p><strong>Here is a summary of the shoot that was scheduled:</strong></p>

    <p>
        Location: {{ $shoot->location }}<br>
        Scheduled Date: {{ $shoot->date }}<br>
        Photographer: {{ $shoot->photographer }}<br>
        Services: @foreach($shoot->packages as $package){{ $package['name'] }}@if(!$loop->last), @endif @endforeach<br>
        Total: {{ number_format($shoot->grand_total, 2) }}
    </p>

    @if($shoot->notes)
    <p>
        <strong>Notes:</strong><br>
        {{ $shoot->notes }}
    </p>
    @endif

    <p>
        To ensure a smooth shoot process, please have the property ready. 
        Here is a link to getting your property ready for the shoot: 
        <a href="https://reprophotos.com/tips-to-get-your-property-camera-ready/">
            Tips to Get Your Property Camera Ready
        </a>
    </p>

    <p>
        For your convenience, you can pay without logging in by clicking the following link: 
        <a href="{{ $paymentLink }}">Pay Now</a>
    </p>

    <p>
        Payment may be made at any time throughout the shoot process. Although the image proofs will be posted to your account prior to payment being made, your final images will not be accessible until payment has been received in full.
    </p>

    <p>
        If you have any questions about this photo shoot please feel free to contact us, or email 
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> directly.
    </p>

    <p>
        <strong>Our Cancellation Policy:</strong> If an appointment is cancelled on-site, a cancellation fee of $60 will be charged. This helps us cover time, travel and administration costs. We ask that you please reschedule or cancel at least 6 hours before the beginning of your appointment.
    </p>

    <p>Thanks for scheduling, we appreciate your business!</p>

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
</body>
</html>
