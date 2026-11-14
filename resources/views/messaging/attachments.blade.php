<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shared files · R/E Pro Photos</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#080f17;color:#eef5ff;font:16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;background-image:radial-gradient(ellipse at top right,#063461 0,transparent 55%)}main{max-width:720px;margin:auto;padding:56px 24px}.brand{font-size:13px;font-weight:700;letter-spacing:.1em;color:#8cbdf5}h1{font-size:34px;letter-spacing:-.04em;margin:24px 0 8px}.description,.size,footer{color:#9aabbf}.files{list-style:none;padding:0;margin:32px 0}.files li{display:flex;align-items:center;gap:20px;padding:20px;background:#111e2c;border:1px solid #263646;border-radius:18px;margin-bottom:12px}.details{min-width:0;flex:1}.name{overflow-wrap:anywhere;font-weight:600}.size{font-size:14px;margin-top:4px}a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border-radius:12px;background:#1579dd;color:white;text-decoration:none;font-size:14px;font-weight:600}a:hover{background:#3696f2}a:focus-visible{outline:3px solid #9bcaff;outline-offset:4px}footer{font-size:13px}@media(max-width:440px){main{padding:32px 18px}.files li{flex-wrap:wrap;gap:14px}.files a{width:100%}}
    </style>
</head>
<body>
<main>
    <div class="brand">R/E PRO PHOTOS</div>
    <h1>Shared files</h1>
    <p class="description">{{ $files->count() }} {{ $files->count() === 1 ? 'file' : 'files' }} · Download the originals below.</p>
    <ul class="files">
        @foreach($files as $file)
        @php
            $size = $file['size'];
            $sizeLabel = $size >= 1073741824 ? number_format($size / 1073741824, 2).' GB' : ($size >= 1048576 ? number_format($size / 1048576, 1).' MB' : ($size >= 1024 ? number_format($size / 1024, 1).' KB' : $size.' bytes'));
        @endphp
        <li>
            <div class="details"><div class="name">{{ $file['name'] }}</div><div class="size">{{ $sizeLabel }}</div></div>
            <a href="{{ $file['url'] }}" download aria-label="Download {{ $file['name'] }}">Download</a>
        </li>
        @endforeach
    </ul>
    <footer>This link expires {{ $expires->format('M j, Y') }}. Anyone with the link can access these files until then.</footer>
</main>
</body>
</html>
