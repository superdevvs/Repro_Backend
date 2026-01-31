<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your Shoot Has Been Marked as Paid</title>
</head>
<body>
    <p>Hello, {{ $user->name }}!</p>

    <p>Great news! Your photo shoot has been marked as paid.</p>

    <p><strong>Payment Details:</strong></p>
    <p>
        Amount Paid: ${{ number_format($amount, 2) }}<br>
        Date: {{ now()->format('M j, Y') }}
    </p>

    <p><strong>Shoot Details:</strong></p>
    <p>
        Location: {{ $shoot->location }}<br>
        Scheduled Date: {{ $shoot->date }}<br>
        Photographer: {{ $shoot->photographer }}
    </p>

    <p>
        @foreach($shoot->packages as $package)
            * {{ $package['name'] }}, [${{ number_format($package['price'], 2) }}]<br>
        @endforeach
    </p>

    <p>
        Your final images are now accessible. Visit 
        <a href="https://reprodashboard.com">https://reprodashboard.com</a> 
        to view and download your photos.
    </p>

    <p>
        If you have any questions please feel free to reply to this email, 
        or email <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> directly.
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
