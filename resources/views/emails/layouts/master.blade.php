<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title', 'R/E Pro Photos')</title>
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333333;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            -webkit-text-size-adjust: 100%;
        }
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-header {
            text-align: center;
            padding: 24px 20px;
            background-color: #f0f0f0 !important;
            border-radius: 12px 12px 0 0;
            border-bottom: 2px solid #e5e7eb;
        }
        .email-header img {
            max-width: 200px;
            height: auto;
        }
        .email-body {
            background-color: #ffffff;
            padding: 30px 24px;
            border-radius: 0 0 12px 12px;
        }
        .email-body p {
            margin-bottom: 14px;
            color: #333333;
        }
        .email-body a {
            color: #2563eb;
        }
        .email-body h3, .email-body h4 {
            color: #1a1a1a;
        }
        .email-body table {
            width: 100%;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background-color: #1a1a2e !important;
                color: #e0e0e0 !important;
            }
            .email-header {
                background-color: #f0f0f0 !important;
                border-bottom-color: #334155 !important;
            }
            .email-body {
                background-color: #1e293b !important;
            }
            .email-body p, .email-body li, .email-body td, .email-body span {
                color: #cbd5e1 !important;
            }
            .email-body h3, .email-body h4, .email-body strong {
                color: #f1f5f9 !important;
            }
            .email-body a {
                color: #60a5fa !important;
            }
        }

        [data-ogsc] .email-header {
            background-color: #f0f0f0 !important;
        }
        [data-ogsc] .email-body {
            background-color: #1e293b !important;
        }
        [data-ogsc] .email-body p {
            color: #cbd5e1 !important;
        }
    </style>
    @yield('extra-styles')
</head>
<body>
    <div class="email-wrapper">
        <!--[if mso]>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="background-color:#f0f0f0;padding:24px 20px;border-radius:12px 12px 0 0;">
        <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos" width="200" style="max-width:200px;height:auto;">
        </td></tr></table>
        <![endif]-->
        <!--[if !mso]><!-->
        <div class="email-header" style="background-color:#f0f0f0 !important;">
            <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos" style="max-width:200px;height:auto;">
        </div>
        <!--<![endif]-->
        <div class="email-body">
            @yield('content')
        </div>
    </div>
</body>
</html>
