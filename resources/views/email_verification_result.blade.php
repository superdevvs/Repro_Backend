<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background:
                radial-gradient(circle at top, rgba(20, 99, 255, 0.18), transparent 34%),
                linear-gradient(180deg, #06101f 0%, #081321 100%);
            color: #e6eef8;
        }

        main {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }

        .shell {
            width: 100%;
            max-width: 760px;
        }

        .card {
            border: 1px solid rgba(132, 164, 206, 0.18);
            border-radius: 28px;
            overflow: hidden;
            background: rgba(10, 20, 36, 0.92);
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.35);
            backdrop-filter: blur(10px);
        }

        .hero {
            padding: 36px 36px 28px;
            background:
                linear-gradient(135deg, rgba(20, 99, 255, 0.22), rgba(15, 118, 110, 0.08)),
                rgba(10, 20, 36, 0.98);
            border-bottom: 1px solid rgba(132, 164, 206, 0.12);
        }

        .content {
            padding: 30px 36px 36px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            color: #96a9c6;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .eyebrow img {
            width: 126px;
            height: auto;
            display: block;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            margin-bottom: 18px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid {{ $success ? 'rgba(96, 223, 191, 0.28)' : 'rgba(251, 113, 133, 0.24)' }};
            background: {{ $success ? 'rgba(15, 118, 110, 0.14)' : 'rgba(190, 24, 93, 0.12)' }};
            color: {{ $success ? '#8ef0d4' : '#ffb0c7' }};
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            max-width: 12ch;
            font-size: clamp(34px, 7vw, 56px);
            line-height: 0.95;
            letter-spacing: -0.05em;
            font-weight: 300;
            color: #f6f9fd;
        }

        h1 strong {
            font-weight: 800;
        }

        .lede {
            margin: 18px 0 0;
            max-width: 54ch;
            font-size: 15px;
            line-height: 1.8;
            color: #a9bdd9;
        }

        .panel {
            border-radius: 20px;
            border: 1px solid rgba(132, 164, 206, 0.16);
            background: rgba(15, 28, 48, 0.88);
            padding: 22px 24px;
        }

        .panel h2 {
            margin: 0 0 10px;
            font-size: 20px;
            line-height: 1.25;
            color: #f6f9fd;
        }

        .panel p {
            margin: 0;
            font-size: 15px;
            line-height: 1.8;
            color: #a9bdd9;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
        }

        .button-primary {
            background: #1463ff;
            color: #ffffff;
            box-shadow: 0 12px 30px rgba(20, 99, 255, 0.25);
        }

        .button-secondary {
            border: 1px solid rgba(132, 164, 206, 0.22);
            background: rgba(255, 255, 255, 0.03);
            color: #d8e4f3;
        }

        .meta {
            margin-top: 22px;
            font-size: 13px;
            line-height: 1.75;
            color: #8ea4c1;
        }

        @media (max-width: 640px) {
            .hero,
            .content {
                padding: 24px 22px;
            }

            .eyebrow {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .actions {
                flex-direction: column;
            }

            .button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="shell">
            <section class="card">
                <div class="hero">
                    <div class="eyebrow">
                        <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos">
                        <span>Email status</span>
                    </div>
                    <div class="status-pill">{{ $success ? 'Verified' : 'Action needed' }}</div>
                    <h1>
                        @if($success)
                            Email <strong>verified.</strong>
                        @else
                            Link <strong>needs attention.</strong>
                        @endif
                    </h1>
                    <p class="lede">{{ $message }}</p>
                </div>
                <div class="content">
                    <div class="panel">
                        <h2>{{ $title }}</h2>
                        <p>
                            @if($success)
                                Your dashboard will now allow normal booking, invoice, portal, and delivery email communication for this client account.
                            @else
                                Open the dashboard to resend verification or update the email address if the current one is wrong.
                            @endif
                        </p>
                    </div>

                    <div class="actions">
                        <a class="button button-primary" href="{{ $dashboardUrl }}">Open dashboard</a>
                        <a class="button button-secondary" href="mailto:contact@reprophotos.com">Contact support</a>
                    </div>

                    <p class="meta">
                        Need help with your account? Email <a href="mailto:contact@reprophotos.com" style="color:#ffffff;">contact@reprophotos.com</a>
                        or call 202-868-1663.
                    </p>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
