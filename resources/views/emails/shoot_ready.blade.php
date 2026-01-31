<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Photos Are Ready!</title>
</head>
<body>
    <p>Hello, {{ $user->name }}!</p>

    <p>
        Your real estate photos have been completed and are now available for download. 
        You can log into your account to access them anytime at: 
        <a href="https://reprodashboard.com">https://reprodashboard.com</a>
    </p>

    <p>
        <strong>Shoot Summary:</strong><br>
        Location: {{ $shoot->location }}<br>
        Shoot Date: {{ $shoot->date }}<br>
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
        Please log in to your dashboard to preview, download, and manage your final images. 
        Dashboard: <a href="https://reprodashboard.com">https://reprodashboard.com</a>
    </p>

    <p>
        If you have any questions about this shoot or need further assistance, 
        please reply to this email or contact us at 
        <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a>.
    </p>

    <p>Thank you for your business!</p>

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
