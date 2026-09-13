<style>
    :root { color-scheme: {{ $theme ?? 'light dark' }}; supported-color-schemes: {{ $theme ?? 'light dark' }}; }
    html, body { margin:0 !important; padding:0 !important; width:100% !important; }
    * { -ms-text-size-adjust:100%; -webkit-text-size-adjust:100%; }
    table { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    td { font-family:Inter,Arial,Helvetica,sans-serif; }
    img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
    a { color:{{ $colors['accent'] }}; }
    .body-bg { background-color:{{ $colors['canvas'] }} !important; }
    .content-card-bg, .hero-card-bg { background-color:{{ $colors['paper'] }} !important; background-image:none !important; }
    .section-card-bg, .stat-card-bg, .note-card-bg, .callout-bg, .callout-success-bg, .callout-warning-bg, .callout-danger-bg, .info-box, .change-card { background-color:{{ $colors['surface'] }} !important; background-image:none !important; border:0 !important; border-radius:12px !important; }
    .dark-title, .dark-heading, .dark-strong, .info-value, .detail-value, .hero-title-primary, .hero-title-accent, .hero-title-location { color:{{ $colors['ink'] }} !important; }
    .dark-body, .body-inner { color:{{ $colors['body'] }} !important; }
    .dark-muted, .info-label, .detail-label, .legal-copy-dark { color:{{ $colors['muted'] }} !important; }
    .email-intro > .dark-muted, .atelier-link, .footer-contact-link { color:{{ $colors['accent'] }} !important; }
    .hero-overline, .hero-title-lead { display:block; }
    .hero-title-td { font-size:36px !important; line-height:42px !important; font-weight:500 !important; letter-spacing:-1.2px !important; }
    .hero-overline { margin-bottom:8px; font-size:16px; line-height:24px; letter-spacing:0; }
    .detail-label-td, .detail-value-td { display:block !important; width:100% !important; box-sizing:border-box; border:0 !important; }
    .detail-label-td { padding:16px 0 4px !important; font-size:11px !important; line-height:16px !important; letter-spacing:1.4px; text-transform:uppercase; }
    .detail-value-td { padding:0 !important; font-size:14px !important; line-height:22px !important; }
    .detail-border { border-color:transparent !important; }
    .info-row, .detail-row { display:block !important; padding:0 0 16px; }
    .info-label, .detail-label { display:block !important; font-size:11px !important; line-height:16px !important; letter-spacing:1.4px; text-transform:uppercase; margin-bottom:4px; }
    .info-value, .detail-value { display:block; font-size:14px; line-height:22px; font-weight:600; }
    .atelier-button, .body-inner .button, .body-inner .cta-button { background-color:#155bdd !important; background-image:none !important; color:#ffffff !important; border-radius:8px !important; }
    .body-inner img { max-width:100%; height:auto; }
    .body-inner table { max-width:100%; }
    .btn-secondary-bg { background-color:{{ $colors['surface'] }} !important; color:{{ $colors['ink'] }} !important; }
    @media only screen and (max-width:600px) {
        .email-outer { padding:16px !important; }
        .email-container { width:100% !important; }
        .email-inset { padding-left:24px !important; padding-right:24px !important; }
        .brand-tagline { display:none !important; }
        .hero-title-td { font-size:30px !important; line-height:36px !important; letter-spacing:-.9px !important; }
        .stat-td, .stack-column { display:block !important; width:100% !important; padding:0 0 12px !important; box-sizing:border-box; }
        .section-inner { padding:24px !important; }
        .line-td, .line-th { overflow-wrap:anywhere; word-break:normal; }
        .footer-brand-column, .footer-social-column { display:block !important; width:100% !important; text-align:left !important; }
        .footer-social-column { padding-top:20px !important; }
        .footer-social-column td { padding-left:0 !important; padding-right:12px !important; }
    }
    @if($theme === null)
        @media (prefers-color-scheme:dark) {
            .body-bg { background-color:#080f17 !important; }
            .content-card-bg, .hero-card-bg { background-color:#121e2c !important; border-color:#2c425e !important; }
            .section-card-bg, .stat-card-bg, .note-card-bg, .callout-bg, .callout-success-bg, .callout-warning-bg, .callout-danger-bg, .info-box, .change-card, .btn-secondary-bg { background-color:#1b2a3e !important; }
            .dark-title, .dark-heading, .dark-strong, .info-value, .detail-value, .hero-title-primary, .hero-title-accent, .hero-title-location, .btn-secondary-bg { color:#f1f5fc !important; }
            .dark-body, .body-inner { color:#bdccdf !important; }
            .dark-muted, .info-label, .detail-label, .legal-copy-dark { color:#8da5c4 !important; }
            .email-intro > .dark-muted, .atelier-link, .footer-contact-link, .body-inner a:not(.atelier-button):not(.button):not(.cta-button) { color:#9cbdff !important; }
            .footer-inner, .footer-rule { border-color:#2c425e !important; }
        }
        [data-ogsc] .body-bg { background-color:#080f17 !important; }
        [data-ogsc] .content-card-bg { background-color:#121e2c !important; border-color:#2c425e !important; }
        [data-ogsc] .section-card-bg, [data-ogsc] .stat-card-bg, [data-ogsc] .note-card-bg, [data-ogsc] .callout-bg, [data-ogsc] .callout-success-bg, [data-ogsc] .callout-warning-bg, [data-ogsc] .callout-danger-bg, [data-ogsc] .info-box, [data-ogsc] .change-card { background-color:#1b2a3e !important; }
        [data-ogsc] .dark-title, [data-ogsc] .dark-heading, [data-ogsc] .dark-strong, [data-ogsc] .info-value, [data-ogsc] .detail-value, [data-ogsc] .hero-title-primary, [data-ogsc] .hero-title-accent { color:#f1f5fc !important; }
        [data-ogsc] .dark-body, [data-ogsc] .body-inner { color:#bdccdf !important; }
        [data-ogsc] .dark-muted, [data-ogsc] .info-label, [data-ogsc] .detail-label { color:#8da5c4 !important; }
        [data-ogsc] .atelier-link, [data-ogsc] .footer-contact-link { color:#9cbdff !important; }
        [data-ogsc] .footer-inner, [data-ogsc] .footer-rule { border-color:#2c425e !important; }
    @endif
</style>
