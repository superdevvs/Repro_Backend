<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Photo Shoot Cancelled</title>
</head>
<body>
    <p>Hello, {{ $user->name }}!</p>

    <p>
        One of your photo shoots has been cancelled or removed from the schedule.
    </p>

    <p>
        Location: {{ $shoot->location }}<br>
        Date: {{ $shoot->date }}<br>
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
        If you need real estate photography services for this property in the future, 
        please feel free to reply to this email, or email 
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> directly.
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
</body>
</html>
