<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#030619">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: {{ $branding['outer_background'] ?? '#030619' }};
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background:
                radial-gradient(1000px 600px at 50% -10%, rgba(59, 130, 246, 0.14), transparent 60%),
                {{ $branding['outer_background'] ?? '#030619' }};
            color: #e6eef8;
            -webkit-font-smoothing: antialiased;
        }

        main {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 20px;
        }

        .logo {
            margin-bottom: 28px;
        }

        .logo img {
            width: 150px;
            height: auto;
            display: block;
        }

        .card {
            width: 100%;
            max-width: 440px;
            border-radius: 20px;
            border: 1px solid rgba(148, 163, 184, 0.14);
            background: rgba(10, 15, 26, 0.85);
            padding: 36px 32px 32px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
        }

        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 1.5px solid {{ $success ? 'rgba(34, 197, 94, 0.35)' : 'rgba(239, 68, 68, 0.35)' }};
            background: {{ $success ? 'rgba(34, 197, 94, 0.1)' : 'rgba(239, 68, 68, 0.08)' }};
            color: {{ $success ? '#4ade80' : '#f87171' }};
        }

        .icon svg {
            width: 30px;
            height: 30px;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
            line-height: 1.25;
            letter-spacing: -0.01em;
            font-weight: 700;
            color: #f6f9fd;
        }

        .message {
            margin: 0 0 28px;
            font-size: 14.5px;
            line-height: 1.65;
            color: #94a3b8;
        }

        .button-primary {
            display: block;
            width: 100%;
            padding: 14px 20px;
            border-radius: 12px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.28);
            transition: background 0.15s ease;
        }

        .button-primary:hover {
            background: #1d4ed8;
        }

        .support {
            margin-top: 20px;
            font-size: 13px;
            line-height: 1.7;
            color: #64748b;
        }

        .support a {
            color: #94a3b8;
            text-decoration: none;
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
        }

        .support a:hover {
            color: #cbd5e1;
        }

        @media (max-width: 480px) {
            main {
                padding: 32px 16px;
            }

            .card {
                padding: 30px 22px 26px;
                border-radius: 18px;
            }

            h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="logo">
            <img src="{{ $branding['verification_logo_light_url'] ?? $branding['verification_logo_url'] ?? 'https://api.reprodashboard.com/images/repro-email-logo-light.png' }}" alt="{{ $branding['product_name'] ?? 'R/E Pro Photos' }}">
        </div>
        <section class="card">
            <div class="icon" aria-hidden="true">
                @if($success)
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                @else
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                @endif
            </div>
            <h1>{{ $title }}</h1>
            <p class="message">{{ $message }}</p>
            <a class="button-primary" href="{{ $dashboardUrl }}">Open dashboard</a>
            <p class="support">
                Need help? <a href="mailto:{{ $branding['support_email'] ?? 'contact@reprophotos.com' }}">{{ $branding['support_email'] ?? 'contact@reprophotos.com' }}</a> &middot; {{ $branding['support_phone'] ?? '202-868-1113' }}
            </p>
        </section>
    </main>
</body>
</html>
