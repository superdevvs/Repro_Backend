<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', 'R/E Pro Photos')</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #eef3f8;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            color: #10233b;
            -webkit-text-size-adjust: 100%;
            word-break: break-word;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        img {
            border: 0;
            display: block;
            max-width: 100%;
        }
        a {
            color: #1463ff;
            text-decoration: none;
        }
        .preheader {
            display: none !important;
            visibility: hidden;
            opacity: 0;
            color: transparent;
            height: 0;
            width: 0;
            overflow: hidden;
            mso-hide: all;
        }
        .page {
            padding: 28px 12px;
        }
        .shell {
            max-width: 680px;
            margin: 0 auto;
        }
        .card {
            background-color: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 22px 60px rgba(13, 35, 67, 0.12);
        }
        .brand-band {
            background: linear-gradient(135deg, #071223 0%, #0b2242 55%, #123e83 100%);
            padding: 22px 28px 18px;
        }
        .brand-meta {
            font-size: 11px;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: #d6e4ff;
        }
        .brand-chip {
            display: inline-block;
            margin-top: 10px;
            background-color: #ffffff;
            border-radius: 18px;
            padding: 16px 22px;
            box-shadow: 0 14px 32px rgba(2, 13, 30, 0.2);
        }
        .brand-chip img {
            width: 176px;
            height: auto;
        }
        .hero-wrap {
            padding: 24px 28px 0;
        }
        .hero-panel {
            border-radius: 24px;
            background: linear-gradient(180deg, #f5f9ff 0%, #edf5ff 100%);
            border: 1px solid #dbe7f8;
            padding: 24px;
        }
        .eyebrow {
            margin: 0 0 10px;
            font-size: 11px;
            line-height: 1.4;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #5d7493;
            font-weight: 700;
        }
        .hero-title {
            margin: 0;
            font-size: 31px;
            line-height: 1.15;
            font-weight: 800;
            color: #071223;
        }
        .hero-copy {
            margin: 14px 0 0;
            font-size: 16px;
            line-height: 1.7;
            color: #4f6886;
        }
        .content {
            padding: 24px 28px 18px;
        }
        .section-card {
            margin: 0 0 18px;
            border: 1px solid #dbe6f3;
            border-radius: 22px;
            background-color: #ffffff;
            overflow: hidden;
        }
        .section-pad {
            padding: 20px 22px;
        }
        .section-kicker {
            margin: 0 0 8px;
            font-size: 11px;
            line-height: 1.4;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: #6c84a2;
            font-weight: 700;
        }
        .section-title {
            margin: 0;
            font-size: 22px;
            line-height: 1.25;
            font-weight: 800;
            color: #071223;
        }
        .section-copy,
        .body-copy {
            margin: 12px 0 0;
            font-size: 15px;
            line-height: 1.75;
            color: #4f6886;
        }
        .intro {
            margin: 0 0 16px;
            font-size: 16px;
            line-height: 1.75;
            color: #2d4769;
        }
        .intro strong {
            color: #071223;
        }
        .stats-row td {
            padding: 0 8px 10px 0;
            vertical-align: top;
        }
        .stat-card {
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f5f9ff 100%);
            border: 1px solid #dbe6f3;
            padding: 16px 16px 14px;
        }
        .stat-label {
            margin: 0 0 6px;
            font-size: 11px;
            line-height: 1.3;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: #7f95b1;
            font-weight: 700;
        }
        .stat-value {
            margin: 0;
            font-size: 22px;
            line-height: 1.2;
            font-weight: 800;
            color: #071223;
        }
        .stat-copy {
            margin: 8px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: #69819f;
        }
        .pill,
        .status-pill {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            line-height: 1.2;
            font-weight: 700;
            margin: 0 8px 8px 0;
        }
        .pill {
            background-color: #edf4ff;
            border: 1px solid #d6e5ff;
            color: #295391;
        }
        .status-pill {
            background-color: #eef8f1;
            border: 1px solid #ccebd6;
            color: #23744c;
        }
        .status-warning {
            background-color: #fff5e8;
            border-color: #ffd8a8;
            color: #ab5b00;
        }
        .status-danger {
            background-color: #fff0f1;
            border-color: #ffcbd1;
            color: #b42336;
        }
        .detail-table td {
            padding: 10px 0;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            font-size: 14px;
            line-height: 1.65;
        }
        .detail-table tr:last-child td {
            border-bottom: 0;
        }
        .detail-label {
            width: 34%;
            color: #6f86a4;
            font-weight: 700;
            padding-right: 14px !important;
        }
        .detail-value {
            color: #10233b;
            font-weight: 600;
        }
        .detail-subvalue {
            display: block;
            margin-top: 3px;
            color: #7086a3;
            font-weight: 400;
            font-size: 12px;
        }
        .line-table th,
        .line-table td {
            padding: 12px 0;
            border-bottom: 1px solid #edf2f7;
            text-align: left;
            vertical-align: top;
        }
        .line-table tr:last-child td {
            border-bottom: 0;
        }
        .line-table th {
            font-size: 11px;
            line-height: 1.4;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #7b91ac;
            font-weight: 800;
        }
        .line-name {
            color: #071223;
            font-weight: 700;
            font-size: 14px;
        }
        .line-meta {
            display: block;
            margin-top: 4px;
            color: #7188a6;
            font-size: 12px;
            line-height: 1.55;
        }
        .amount-cell {
            text-align: right !important;
            white-space: nowrap;
            color: #071223;
            font-weight: 800;
            font-size: 14px;
        }
        .divider {
            height: 1px;
            background-color: #edf2f7;
            margin: 18px 0;
        }
        .note-card {
            border-radius: 18px;
            background-color: #f8fbff;
            border: 1px solid #dbe7f8;
            padding: 18px;
            margin: 0 0 16px;
        }
        .note-title {
            margin: 0 0 8px;
            font-size: 13px;
            line-height: 1.5;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: #60799a;
            font-weight: 800;
        }
        .note-card p,
        .note-card li,
        .note-card div {
            color: #35506f;
            font-size: 14px;
            line-height: 1.7;
            margin: 0;
        }
        .bullet-list {
            margin: 0;
            padding-left: 18px;
            color: #35506f;
        }
        .bullet-list li {
            margin-bottom: 8px;
        }
        .change-list {
            margin: 0;
            padding-left: 18px;
            color: #10233b;
        }
        .change-list li {
            margin-bottom: 10px;
            line-height: 1.65;
        }
        .button-row {
            margin: 18px 0 8px;
            text-align: left;
        }
        .button {
            display: inline-block;
            padding: 14px 22px;
            border-radius: 999px;
            background: linear-gradient(135deg, #1463ff 0%, #0b83ff 100%);
            color: #ffffff !important;
            font-weight: 800;
            font-size: 14px;
            line-height: 1.2;
            text-decoration: none;
            margin: 0 10px 10px 0;
            box-shadow: 0 12px 24px rgba(20, 99, 255, 0.18);
        }
        .button-secondary {
            background: #ffffff;
            color: #173963 !important;
            border: 1px solid #cfe0f5;
            box-shadow: none;
        }
        .callout {
            padding: 18px 20px;
            border-radius: 18px;
            border: 1px solid #dce7f5;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            margin: 0 0 18px;
        }
        .callout-warning {
            background: linear-gradient(180deg, #fff8ef 0%, #fff3e3 100%);
            border-color: #ffdcae;
        }
        .callout-danger {
            background: linear-gradient(180deg, #fff4f5 0%, #fff0f1 100%);
            border-color: #ffc8cf;
        }
        .callout-success {
            background: linear-gradient(180deg, #f1fbf5 0%, #eaf8f0 100%);
            border-color: #caecd5;
        }
        .callout-title {
            margin: 0 0 8px;
            font-size: 16px;
            line-height: 1.4;
            color: #071223;
            font-weight: 800;
        }
        .callout-copy {
            margin: 0;
            font-size: 14px;
            line-height: 1.7;
            color: #47627f;
        }
        .fineprint {
            margin: 16px 0 0;
            color: #7890ab;
            font-size: 12px;
            line-height: 1.7;
        }
        .footer-wrap {
            padding: 0 28px 28px;
        }
        .footer-card {
            border-radius: 22px;
            background: linear-gradient(135deg, #0b1b30 0%, #102847 100%);
            padding: 22px;
            color: #dce8ff;
        }
        .footer-title {
            margin: 0 0 8px;
            font-size: 15px;
            line-height: 1.5;
            color: #ffffff;
            font-weight: 800;
        }
        .footer-copy,
        .footer-copy a {
            color: #c8d8f4 !important;
            font-size: 13px;
            line-height: 1.8;
        }
        .footer-links a {
            display: inline-block;
            margin-right: 14px;
            color: #ffffff !important;
            font-weight: 700;
        }
        .footer-note {
            padding: 16px 20px 2px;
            text-align: center;
            color: #7a8ca6;
            font-size: 12px;
            line-height: 1.7;
        }
        @media only screen and (max-width: 640px) {
            .page {
                padding: 14px 8px;
            }
            .brand-band,
            .hero-wrap,
            .content,
            .footer-wrap {
                padding-left: 18px !important;
                padding-right: 18px !important;
            }
            .hero-panel,
            .section-pad,
            .footer-card {
                padding: 18px !important;
            }
            .hero-title {
                font-size: 26px !important;
            }
            .detail-label,
            .detail-value,
            .line-table th,
            .line-table td {
                display: block;
                width: 100% !important;
                text-align: left !important;
            }
            .line-table th {
                padding-bottom: 6px !important;
            }
            .amount-cell {
                text-align: left !important;
                padding-top: 4px !important;
            }
        }
    </style>
    @yield('extra-styles')
</head>
<body>
    <div class="preheader">@yield('preheader', 'R/E Pro Photos update')</div>
    <div class="page">
        <div class="shell">
            <div class="card">
                <div class="brand-band">
                    <div class="brand-meta">R/E Pro Photos | Premium real estate media</div>
                    <div class="brand-chip">
                        <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos">
                    </div>
                </div>

                @hasSection('hero')
                    <div class="hero-wrap">
                        <div class="hero-panel">
                            @yield('hero')
                        </div>
                    </div>
                @endif

                <div class="content">
                    @yield('content')
                </div>

                <div class="footer-wrap">
                    <div class="footer-card">
                        <div class="footer-title">Need help with a shoot, invoice, or account question?</div>
                        <div class="footer-copy">
                            Our team is here to help keep your marketing workflow moving.
                            Reach us at <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> or call 202-868-1663.
                        </div>
                        <div class="footer-copy footer-links" style="margin-top:14px;">
                            <a href="https://reprodashboard.com">Dashboard</a>
                            <a href="https://reprophotos.com">Website</a>
                            <a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews">Leave a Review</a>
                        </div>
                        @hasSection('footer_note')
                            <div class="fineprint">@yield('footer_note')</div>
                        @endif
                    </div>
                    <div class="footer-note">
                        This email was sent by R/E Pro Photos. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
