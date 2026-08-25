<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $payload->title }}</title>
    <meta name="description" content="{{ $payload->description }}">
    <link rel="canonical" href="{{ $payload->url }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $payload->branded || in_array($payload->type, ['dashboard', 'portal'], true) ? 'R/E Pro Photos' : 'Property Tour' }}">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="{{ $payload->title }}">
    <meta property="og:description" content="{{ $payload->description }}">
    <meta property="og:url" content="{{ $payload->url }}">
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:image:secure_url" content="{{ $imageUrl }}">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="{{ $width }}">
    <meta property="og:image:height" content="{{ $height }}">
    <meta property="og:image:alt" content="{{ $imageAlt }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $payload->title }}">
    <meta name="twitter:description" content="{{ $payload->description }}">
    <meta name="twitter:image" content="{{ $imageUrl }}">
    <meta name="twitter:image:alt" content="{{ $imageAlt }}">

    <style>
        html, body { height: 100%; margin: 0; }
        body { display: grid; place-items: center; background: #060a0e; color: #fff; font: 16px/1.5 system-ui, sans-serif; }
        a { color: #75bfff; }
    </style>
</head>
<body>
    <p>Opening <a href="{{ $payload->url }}">{{ $payload->title }}</a>&hellip;</p>
    <script>window.location.replace(@json($payload->url));</script>
</body>
</html>
