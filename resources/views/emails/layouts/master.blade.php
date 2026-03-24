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
            margin: 0;
            padding: 0;
            background: #eef3f8;
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
            padding: 30px 12px;
        }
        .shell {
            max-width: 720px;
            margin: 0 auto;
        }
        .card {
            background: transparent;
            box-shadow: none;
        }
        .hero-card,
        .content-card {
            background-color: #ffffff;
            border-radius: 34px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(22, 34, 60, 0.09);
            border: 1px solid rgba(222, 230, 241, 0.7);
        }
        .hero-card + .content-card {
            margin-top: 18px;
        }
        .hero-card {
            position: relative;
            padding: 34px 38px 28px;
        }
        .hero-brand {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }
        .hero-logo {
            display: inline-block;
            flex-shrink: 0;
        }
        .hero-logo img {
            width: 154px;
            height: auto;
        }
        .hero-brand-copy {
            text-align: right;
            color: #1d2940;
            font-size: 16px;
            line-height: 1.4;
            font-weight: 800;
        }
        .hero-panel {
            position: relative;
            z-index: 2;
            max-width: 560px;
        }
        .eyebrow {
            margin: 0 0 12px;
            font-size: 11px;
            line-height: 1.4;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #5d7493;
            font-weight: 700;
        }
        .hero-title {
            margin: 0;
            font-size: 48px;
            line-height: 0.96;
            font-weight: 300;
            letter-spacing: -2.4px;
            color: #10192f;
        }
        .hero-copy {
            margin: 20px 0 0;
            font-size: 15px;
            line-height: 1.8;
            color: #667a96;
        }
        .content {
            padding: 30px 32px;
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
            background-color: #edf4ff;
            border: 1px solid #d6e5ff;
            color: #295391;
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
        .button-large {
            padding: 18px 30px;
            font-size: 16px;
            letter-spacing: 0.2px;
            box-shadow: 0 16px 30px rgba(20, 99, 255, 0.22);
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
            background: linear-gradient(180deg, #f7fbff 0%, #eff6ff 100%);
            border-color: #d9e7ff;
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
            padding: 18px 0 0;
        }
        .footer-card {
            border-radius: 26px;
            background: linear-gradient(135deg, #0b1b30 0%, #102847 100%);
            padding: 24px 26px;
            color: #dce8ff;
            box-shadow: 0 20px 40px rgba(16, 40, 71, 0.18);
        }
        .footer-title {
            margin: 0 0 8px;
            font-size: 18px;
            line-height: 1.5;
            color: #ffffff;
            font-weight: 800;
        }
        .footer-copy,
        .footer-copy a {
            color: #dce8ff !important;
            font-size: 14px;
            line-height: 1.8;
        }
        .footer-links a {
            display: inline-block;
            margin-right: 14px;
            color: #ffffff !important;
            font-weight: 700;
        }
        .footer-meta {
            width: 100%;
            margin-top: 18px;
        }
        .footer-meta-cell {
            width: 50%;
            padding-right: 12px;
        }
        .footer-meta-card {
            border-radius: 18px;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(221, 232, 255, 0.14);
        }
        .footer-meta-label {
            display: block;
            margin-bottom: 4px;
            color: #9fb4d4;
            font-size: 11px;
            line-height: 1.4;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            font-weight: 800;
        }
        .footer-meta-value {
            color: #ffffff;
            font-size: 14px;
            line-height: 1.6;
            font-weight: 700;
        }
        .footer-note {
            padding: 14px 8px 0;
            text-align: center;
            color: #7d90ab;
            font-size: 11px;
            line-height: 1.7;
        }
        @media only screen and (max-width: 640px) {
            .page {
                padding: 14px 8px;
            }
            .hero-card {
                padding: 24px 22px !important;
            }
            .content,
            .section-pad,
            .footer-card {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
            .hero-title {
                font-size: 32px !important;
                letter-spacing: -1.6px !important;
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
        @media (prefers-color-scheme: dark) {
            body {
                background: #0b1220 !important;
                color: #d7e2f0 !important;
            }
            .hero-card,
            .content-card,
            .section-card,
            .stat-card,
            .note-card,
            .callout {
                background: #111c2e !important;
                border-color: #24344d !important;
                box-shadow: none !important;
            }
            .hero-title,
            .section-title,
            .stat-value,
            .detail-value,
            .line-name,
            .callout-title,
            .intro strong {
                color: #f5f7fb !important;
            }
            .hero-copy,
            .body-copy,
            .section-copy,
            .intro,
            .detail-label,
            .detail-subvalue,
            .line-meta,
            .note-card p,
            .note-card li,
            .note-card div,
            .fineprint,
            .footer-note,
            .section-kicker,
            .eyebrow,
            .stat-label,
            .stat-copy {
                color: #a9b8cb !important;
            }
            .detail-table td,
            .line-table th,
            .line-table td,
            .divider {
                border-color: #24344d !important;
            }
            .pill,
            .status-pill {
                background: #172843 !important;
                border-color: #2b4b78 !important;
                color: #9fc2ff !important;
            }
            .button-secondary {
                background: #142237 !important;
                color: #dfe8f5 !important;
                border-color: #314662 !important;
            }
            .footer-card,
            .footer-meta-card {
                box-shadow: none !important;
            }
            .footer-meta-card {
                background: rgba(17, 28, 46, 0.72) !important;
                border-color: rgba(83, 110, 150, 0.45) !important;
            }
        }
        [data-ogsc] body,
        [data-ogsc] .page {
            background: #0b1220 !important;
            color: #d7e2f0 !important;
        }
        [data-ogsc] .hero-card,
        [data-ogsc] .content-card,
        [data-ogsc] .section-card,
        [data-ogsc] .stat-card,
        [data-ogsc] .note-card,
        [data-ogsc] .callout {
            background: #111c2e !important;
            border-color: #24344d !important;
            box-shadow: none !important;
        }
        [data-ogsc] .hero-title,
        [data-ogsc] .section-title,
        [data-ogsc] .stat-value,
        [data-ogsc] .detail-value,
        [data-ogsc] .line-name,
        [data-ogsc] .callout-title,
        [data-ogsc] .intro strong {
            color: #f5f7fb !important;
        }
        [data-ogsc] .hero-copy,
        [data-ogsc] .body-copy,
        [data-ogsc] .section-copy,
        [data-ogsc] .intro,
        [data-ogsc] .detail-label,
        [data-ogsc] .detail-subvalue,
        [data-ogsc] .line-meta,
        [data-ogsc] .note-card p,
        [data-ogsc] .note-card li,
        [data-ogsc] .note-card div,
        [data-ogsc] .fineprint,
        [data-ogsc] .footer-note,
        [data-ogsc] .section-kicker,
        [data-ogsc] .eyebrow,
        [data-ogsc] .stat-label,
        [data-ogsc] .stat-copy {
            color: #a9b8cb !important;
        }
        [data-ogsc] .pill,
        [data-ogsc] .status-pill {
            background: #172843 !important;
            border-color: #2b4b78 !important;
            color: #9fc2ff !important;
        }
    </style>
    @yield('extra-styles')
</head>
<body>
    <div class="preheader">@yield('preheader', 'Updates from R/E Pro Photos')&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
    <div class="page">
        <div class="shell">
            <div class="card">
                @hasSection('hero')
                    <div class="hero-card">
                        <div class="hero-brand">
                            <div class="hero-logo">
                                <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos" width="154" style="display:block; width:154px; max-width:154px; height:auto; border:0;">
                            </div>
                        </div>
                        <div class="hero-panel">
                            @yield('hero')
                        </div>
                    </div>
                @endif

                <div class="content-card">
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
                            <table class="footer-meta" role="presentation">
                                <tr>
                                    <td class="footer-meta-cell">
                                        <div class="footer-meta-card">
                                            <span class="footer-meta-label">Support</span>
                                            <span class="footer-meta-value">contact@reprophotos.com<br>202-868-1663</span>
                                        </div>
                                    </td>
                                    <td class="footer-meta-cell" style="padding-right:0;">
                                        <div class="footer-meta-card">
                                            <span class="footer-meta-label">Portal</span>
                                            <span class="footer-meta-value">Track your shoots, invoices, and delivery updates in one place.</span>
                                        </div>
                                    </td>
                                </tr>
                            </table>
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
    </div>
</body>
</html>
