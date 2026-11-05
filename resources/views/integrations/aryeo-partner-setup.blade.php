<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Zillow / Aryeo integration setup · RePro Dashboard</title>
    <style nonce="{{ $nonce }}">
        :root { color-scheme: dark; --bg:#080d12; --panel:#101820; --line:#26333f; --text:#f4f7fa; --muted:#a5b5c4; --blue:#359cff; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:16px/1.6 Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        a { color:#9ecfff; text-underline-offset:4px; }
        button,a { -webkit-tap-highlight-color:transparent; }
        a:focus-visible,button:focus-visible,textarea:focus-visible { outline:3px solid #8fc9ff; outline-offset:4px; }
        .wrap { max-width:1140px; margin:auto; padding:0 36px; }
        header { display:flex; justify-content:space-between; gap:24px; align-items:center; min-height:100px; border-bottom:1px solid var(--line); }
        .brand { display:flex; align-items:center; gap:20px; }
        .brand img { width:112px; height:auto; display:block; }
        .brand span { border-left:1px solid var(--line); padding-left:20px; color:var(--muted); font-size:14px; }
        .header-link { font-size:14px; text-decoration:none; }
        main { padding:64px 0 48px; }
        .eyebrow { color:var(--blue); font-size:12px; font-weight:650; letter-spacing:.16em; text-transform:uppercase; margin:0 0 14px; }
        h1 { font-size:clamp(32px,5vw,50px); line-height:1.14; letter-spacing:-.04em; margin:0 0 20px; font-weight:600; }
        .intro { max-width:680px; color:var(--muted); margin:0 0 24px; font-size:18px; }
        .status { display:inline-flex; align-items:center; gap:10px; color:#f0c985; background:#282219; border:1px solid #58462c; padding:7px 13px; border-radius:100px; font-size:13px; }
        .dot { height:7px; width:7px; border-radius:50%; background:#e6b660; flex:none; }
        .notice { margin:24px 0 0; border-left:3px solid #e6b660; background:#211e18; padding:15px 20px; color:#f4d5a1; border-radius:0 10px 10px 0; }
        .grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(0,1fr); gap:24px; margin-top:40px; align-items:start; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; min-width:0; }
        .card-head { padding:26px 28px 20px; border-bottom:1px solid var(--line); }
        .step { color:var(--blue); font-size:12px; font-weight:650; letter-spacing:.08em; margin:0 0 9px; text-transform:uppercase; }
        h2 { font-size:20px; line-height:1.4; margin:0 0 7px; letter-spacing:-.02em; font-weight:600; }
        .card-head p:last-child { color:var(--muted); font-size:14px; margin:0; }
        .card-body { padding:24px 28px 28px; }
        dl { margin:0; }
        .detail { margin-bottom:23px; }
        dt,.label { color:var(--muted); font-size:12px; letter-spacing:.04em; margin:0 0 5px; display:block; }
        dd { margin:0; font-size:15px; }
        .uri { background:#080e14; border:1px solid #365574; border-radius:10px; padding:15px; }
        .uri code { color:#c5e2ff; font:13px/1.75 ui-monospace,SFMono-Regular,Consolas,monospace; overflow-wrap:anywhere; display:block; }
        .uri-note { color:var(--muted); font-size:12px; margin:10px 0 18px; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .button { display:inline-flex; align-items:center; justify-content:center; min-height:42px; border:1px solid #435361; border-radius:8px; padding:9px 15px; color:var(--text); font:inherit; font-size:13px; line-height:1.4; font-weight:600; background:#1b2833; text-decoration:none; cursor:pointer; }
        .button.primary { background:#087cec; border-color:#087cec; color:white; }
        .button:hover { filter:brightness(1.14); }
        .feedback { color:#98cfab; font-size:12px; min-height:20px; margin:12px 0 0; }
        ol { padding:0; margin:0; list-style:none; counter-reset:requirements; }
        li { counter-increment:requirements; display:flex; gap:12px; margin:0 0 19px; font-size:14px; color:#d4dde5; }
        li:last-child { margin:0; }
        li::before { content:counter(requirements); border:1px solid #344351; border-radius:50%; font-size:11px; width:23px; height:23px; display:grid; place-items:center; color:var(--muted); flex:none; margin-top:1px; }
        .secure-note { border-top:1px solid var(--line); color:var(--muted); font-size:12px; padding:18px 28px; }
        .state-note { display:flex; gap:13px; margin:25px 0 0; color:var(--muted); font-size:13px; padding:0 2px; }
        .state-note strong { color:#d4dde5; font-weight:550; }
        details { margin-top:28px; border-top:1px solid var(--line); padding-top:24px; }
        summary { color:#d4dde5; cursor:pointer; font-size:14px; }
        textarea { margin-top:14px; resize:vertical; width:100%; min-height:228px; background:#0c141c; border:1px solid var(--line); border-radius:10px; color:#bfcfdd; padding:16px; font:12px/1.7 ui-monospace,SFMono-Regular,Consolas,monospace; }
        footer { color:#7c91a3; font-size:12px; padding:23px 0 32px; border-top:1px solid var(--line); display:flex; justify-content:space-between; gap:15px; }
        @media(max-width:760px) { .wrap { padding:0 22px; } header { min-height:84px; } .brand img { width:95px; } .brand span { display:none; } main { padding-top:40px; } .intro { font-size:16px; } .grid { grid-template-columns:minmax(0,1fr); margin-top:30px; gap:20px; } .card-head { padding:22px; } .card-body { padding:22px; } .secure-note { padding:18px 22px; } footer { flex-direction:column; } }
        @media(prefers-reduced-motion:no-preference) { .button { transition:filter .15s ease; } }
        @media print { :root { color-scheme:light; --bg:white; --panel:white; --line:#ccc; --text:#111; --muted:#555; --blue:#1263a6; } .brand img,.actions,.header-link,details { display:none; } .grid { grid-template-columns:1fr; } .uri { background:white; } .uri code { color:#111; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand"><img src="data:image/png;base64,{{ $logo }}" alt="R/E Pro Photos" width="112"><span>Partner integrations</span></div>
        <a class="header-link" href="{{ $details['application_url'] }}">Open dashboard ↗</a>
    </header>
    <main>
        <p class="eyebrow">RePro Dashboard · Partner setup</p>
        <h1>Zillow / Aryeo integration</h1>
        <p class="intro">Our registration details, in one place. Share this page with your partner team to register the connection and confirm the API requirements.</p>
        <div class="status"><span class="dot" aria-hidden="true"></span>Awaiting partner configuration</div>
        @if ($callbackUnavailable)
            <p class="notice" role="status">The callback is reachable, but account connection is not enabled yet. No authorization code was processed and no account was linked.</p>
        @endif
        <div class="grid">
            <section class="card" aria-labelledby="registration-heading">
                <div class="card-head"><p class="step">01 · Register the application</p><h2 id="registration-heading">Application details</h2><p>Use the exact redirect URI below.</p></div>
                <div class="card-body">
                    <dl>
                        <div class="detail"><dt>Application name</dt><dd>{{ $details['application_name'] }}</dd></div>
                        <div class="detail"><dt>Application URL</dt><dd><a href="{{ $details['application_url'] }}">{{ $details['application_url'] }}</a></dd></div>
                    </dl>
                    <span class="label">OAuth redirect URI / callback URL</span>
                    <div class="uri"><code id="redirect-uri">{{ $details['redirect_uri'] }}</code></div>
                    <p class="uri-note">HTTPS · Exact match · No trailing slash</p>
                    <div class="actions"><button type="button" class="button primary" id="copy-uri">Copy callback URL</button><a class="button" href="{{ $details['setup_url'] }}.json">View registration JSON</a></div>
                    <p id="copy-feedback" class="feedback" role="status" aria-live="polite"></p>
                </div>
                <div class="secure-note">This public page contains registration details only. Credentials and account data do not belong here.</div>
            </section>
            <section class="card" aria-labelledby="partner-heading">
                <div class="card-head"><p class="step">02 · Confirm with the partner</p><h2 id="partner-heading">What we need to connect</h2><p>The partner specification determines the final flow.</p></div>
                <div class="card-body"><ol>@foreach ($details['required_partner_details'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ol></div>
                <div class="secure-note">Send secrets through an agreed private channel. Please do not place them in this page's URL or registration JSON.</div>
            </section>
        </div>
        <p class="state-note"><span class="dot" aria-hidden="true"></span><span><strong>Callback reserved. Connection pending.</strong> Authorization and token exchange are disabled. This page does not link accounts or enable API synchronization.</span></p>
        <details><summary>Ready-to-share registration note</summary><textarea id="registration-note" readonly aria-label="Registration details to share">Application: {{ $details['application_name'] }}
Application URL: {{ $details['application_url'] }}
Integration: {{ $details['integration'] }}
Redirect URI: {{ $details['redirect_uri'] }}
Setup details: {{ $details['setup_url'] }}

Our HTTPS callback is reserved. Please register the exact redirect URI and provide the partner API/OAuth specification, client registration, approved scopes, and sandbox details. Account connection is pending that configuration. Please arrange private delivery of any client secret.</textarea></details>
    </main>
    <footer><span>R/E Pro Photos · RePro Dashboard</span><span>Partner registration handoff · No account access required</span></footer>
</div>
<script nonce="{{ $nonce }}">
    const copyButton = document.getElementById('copy-uri');
    const copyFeedback = document.getElementById('copy-feedback');
    copyButton.addEventListener('click', async () => {
        const uri = document.getElementById('redirect-uri');
        try {
            await navigator.clipboard.writeText(uri.textContent.trim());
            copyFeedback.textContent = 'Callback URL copied.';
        } catch {
            const range = document.createRange();
            range.selectNodeContents(uri);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            copyFeedback.textContent = 'URL selected. Use your device’s copy command.';
        }
    });
</script>
</body>
</html>
