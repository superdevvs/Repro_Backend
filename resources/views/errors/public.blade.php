<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $pageTitle }}</title>
  <meta name="robots" content="noindex, nofollow" />
@verbatim
  <style>
    :root {
      color-scheme: dark;
      --paper: #071018;
      --ink: #eef3f8;
      --muted: #b4c2d1;
      --line: #6ea4dc;
      --glyph: #7eb4e6;
      --line-soft: rgba(110, 164, 220, .28);
      --grid: rgba(110, 164, 220, .1);
      --btn: #3b82f6;
      --btn-ink: #fff;
      --ghost-line: #2a3b50;
      --room-sky-a: #122232;
      --room-sky-b: #1a2d40;
      --room-floor: #223344;
      --room-city: #3d556b;
      --room-chair: #9eb3c6;
      --room-seat: #7e94a8;
      --mark: #d5e6f6;
    }
    html[data-theme="light"] {
      color-scheme: light;
      --paper: #f4f7fb;
      --ink: #101828;
      --muted: #5c6b7a;
      --line: #7ea4cc;
      --glyph: #5c94cb;
      --line-soft: rgba(126, 164, 204, .38);
      --grid: rgba(110, 150, 190, .13);
      --btn: #1a5fe0;
      --btn-ink: #fff;
      --ghost-line: #c5d2e0;
      --room-sky-a: #c8dcef;
      --room-sky-b: #e8eef5;
      --room-floor: #e4ebf2;
      --room-city: #9aafc3;
      --room-chair: #5e7388;
      --room-seat: #7d92a6;
      --mark: #3a6894;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      min-height: 100vh;
      font-family: Inter, system-ui, sans-serif;
      color: var(--ink);
      background:
        linear-gradient(var(--grid) 1px, transparent 1px),
        linear-gradient(90deg, var(--grid) 1px, transparent 1px),
        var(--paper);
      background-size: 24px 24px, 24px 24px, auto;
      background-position: center;
    }
    ::selection { background: #1d4a7a; color: #fff; }
    html[data-theme="light"] ::selection { background: #c9ddf5; color: #122033; }
    .chrome { position: absolute; top: 0; right: 0; z-index: 10; display: flex; gap: 6px; padding: 14px 16px; }
    .chrome button {
      appearance: none;
      border: 1px solid var(--ghost-line);
      background: color-mix(in srgb, var(--paper) 88%, #fff);
      color: var(--ink);
      border-radius: 999px;
      min-height: 34px;
      padding: 0 13px;
      font: 500 12px/1 Inter, sans-serif;
      cursor: pointer;
    }
    .chrome button[aria-pressed="true"] { background: var(--btn); border-color: var(--btn); color: #fff; }
    .chrome button:focus-visible, .cta:focus-visible, .ghost:focus-visible, .brand:focus-visible {
      outline: 2px solid var(--btn);
      outline-offset: 3px;
    }
    .page {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 36px 20px 40px;
      text-align: center;
    }
    .brand img { height: 32px; width: auto; display: block; }
    html[data-theme="light"] .brand .on-dark { display: none; }
    html[data-theme="dark"] .brand .on-light { display: none; }
    .drawing { width: min(920px, 100%); margin: 36px 0 8px; }
    .drawing svg { width: 100%; height: auto; display: block; overflow: visible; }
    .guide, .dim, .tick { fill: none; stroke: var(--line); stroke-linecap: square; }
    .guide { stroke-width: 1; stroke: var(--line-soft); stroke-dasharray: 4 5; }
    .dim, .tick { stroke-width: 1.05; }
    .glyph { fill: var(--glyph); stroke: none; }
    .ink { fill: none; stroke: var(--mark); stroke-width: 1; stroke-linecap: square; opacity: .55; }
    .ink-dash { stroke-dasharray: 4 5; }
    .room-sky { fill: url(#room-sky); }
    .room-city { fill: var(--room-city); }
    .room-city-far { fill: var(--room-city); opacity: .42; }
    .city-win { fill: var(--room-sky-b); opacity: .85; }
    .door-handle { fill: none; stroke: var(--mark); stroke-width: 1.4; stroke-linecap: round; }
    .room-furn { fill: none; stroke: var(--room-chair); stroke-width: 1.2; stroke-linejoin: round; stroke-linecap: round; }
    .room-seat-fill { fill: color-mix(in srgb, var(--room-chair) 55%, var(--room-floor)); }
    .cam-body { fill: color-mix(in srgb, var(--room-chair) 72%, var(--room-floor)); stroke: var(--room-chair); stroke-width: 1.15; stroke-linejoin: round; }
    .cam-lens { fill: color-mix(in srgb, var(--room-sky-b) 55%, var(--room-chair)); stroke: var(--room-chair); stroke-width: 1.15; }
    .cam-lens-in { fill: none; stroke: var(--mark); stroke-width: 1; opacity: .55; }
    .cam-glint { fill: var(--mark); opacity: .2; }
    .camera-idle { transform-box: fill-box; transform-origin: 50% 100%; }
    .draw .camera-idle { animation: idle 4.6s cubic-bezier(.45, .08, .2, 1) .8s infinite; }
    .draw .cam-glint { animation: glint 4.6s ease-in-out .8s infinite; }
    .plinth { fill: var(--glyph); opacity: .22; }
    .plinth-edge { fill: none; stroke: var(--mark); stroke-width: .75; opacity: .18; }
    .label { fill: var(--line); font-family: Inter, system-ui, sans-serif; font-size: 12px; font-weight: 500; letter-spacing: .1em; }
    .copy h1 { margin: 28px 0 12px; font-size: clamp(16px, 2.2vw, 20px); font-weight: 600; letter-spacing: .14em; text-transform: uppercase; }
    .copy p { margin: 0 0 28px; color: var(--muted); font-size: 15px; line-height: 1.55; }
    .actions { display: flex; flex-direction: column; align-items: center; gap: 18px; }
    .cta, .ghost { display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; font-size: 14px; font-weight: 600; }
    .cta { min-height: 48px; padding: 0 30px; border-radius: 999px; background: var(--btn); color: var(--btn-ink); }
    .cta:hover { filter: brightness(1.06); }
    .ghost { color: var(--ink); border-bottom: 1px solid var(--ink); padding-bottom: 2px; }
    .ghost:hover { opacity: .7; }
    .foot { margin-top: 40px; font-size: 11px; letter-spacing: .2em; text-transform: uppercase; color: var(--muted); }
    .draw .glyph { animation: rise 1s cubic-bezier(.16, 1, .3, 1) both; }
    .draw .four-b { animation-delay: .08s; }
    .draw .gate { animation-delay: .14s; }
    .draw .room { opacity: 0; animation: fade .8s .3s ease forwards; }
    .draw .furn { opacity: 0; animation: fade .7s .55s ease forwards; }
    .draw .ink, .draw .guide { opacity: 0; animation: fade .6s .5s ease forwards; }
    .draw .dim, .draw .tick, .draw .label { opacity: 0; animation: fade .55s .7s ease forwards; }
    .draw .plinth { animation: rise 1s cubic-bezier(.16, 1, .3, 1) both; }
    @keyframes rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
    @keyframes fade { to { opacity: 1; } }
    @keyframes idle {
      0%, 100% { transform: translateY(0) rotate(0); }
      28% { transform: translateY(-2.4px) rotate(-1.5deg); }
      62% { transform: translateY(-.8px) rotate(.7deg); }
    }
    @keyframes glint { 0%, 100% { opacity: .12; } 42% { opacity: .72; } 58% { opacity: .2; } }
    @media (prefers-reduced-motion: reduce) {
      .draw .glyph, .draw .room, .draw .furn, .draw .ink, .draw .guide, .draw .dim, .draw .tick, .draw .label, .draw .plinth, .draw .camera-idle, .draw .cam-glint {
        animation: none;
        opacity: 1;
      }
    }
  </style>
@endverbatim
</head>
<body>
  <div class="chrome">
    <button type="button" data-theme="light" aria-pressed="false">Light</button>
    <button type="button" data-theme="dark" aria-pressed="true">Dark</button>
  </div>
  <main class="page">
    <a class="brand" href="{{ $homeUrl }}" aria-label="{{ $brandName }}">
      <img class="on-light" src="{{ $logoOnLight }}" alt="{{ $brandName }}" />
      <img class="on-dark" src="{{ $logoOnDark }}" alt="{{ $brandName }}" />
    </a>
    <div class="drawing draw" aria-hidden="true">
      <svg viewBox="0 0 1040 400">
        <defs>
          <linearGradient id="room-sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="var(--room-sky-a)" />
            <stop offset="1" stop-color="var(--room-sky-b)" />
          </linearGradient>
          <linearGradient id="city-fade" x1="0" y1="348" x2="0" y2="197" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#fff" />
            <stop offset=".35" stop-color="#fff" stop-opacity=".95" />
            <stop offset=".65" stop-color="#fff" stop-opacity=".5" />
            <stop offset="1" stop-color="#fff" stop-opacity=".18" />
          </linearGradient>
          <mask id="city-mask" maskUnits="userSpaceOnUse">
            <rect x="450" y="124" width="140" height="224" fill="url(#city-fade)" />
          </mask>
          <clipPath id="door">
            <rect x="450" y="124" width="140" height="224" />
          </clipPath>
        </defs>
        <g class="guide">
          <line x1="24" y1="200" x2="1016" y2="200" />
          <line x1="216" y1="22" x2="216" y2="378" />
          <line x1="520" y1="22" x2="520" y2="378" />
          <line x1="824" y1="22" x2="824" y2="378" />
        </g>
        <rect class="plinth" x="98" y="348" width="844" height="2" />
        <line class="plinth-edge" x1="98" y1="348" x2="942" y2="348" />
        <g class="glyph four-a">
          <rect x="294" y="68" width="56" height="280" />
          <rect x="98" y="228" width="196" height="56" />
          <path d="M98 228 L294 68 L294 124 L170 228 Z" />
        </g>
        <g class="room" clip-path="url(#door)">
          <rect class="room-sky" x="450" y="124" width="140" height="224" />
          <g class="skyline" mask="url(#city-mask)">
            <g class="room-city-far">
              <rect x="478" y="270" width="10" height="78" />
              <rect x="548" y="272" width="12" height="76" />
            </g>
            <g class="room-city">
              <rect x="454" y="264" width="10" height="84" />
              <rect x="466" y="249" width="14" height="99" />
              <rect class="city-win" x="468" y="251" width="3" height="6" />
              <rect class="city-win" x="473" y="256" width="3" height="6" />
              <rect class="city-win" x="468" y="263" width="3" height="6" />
              <rect class="city-win" x="473" y="267" width="3" height="6" />
              <rect x="482" y="228" width="14" height="120" />
              <rect x="485" y="218" width="8" height="10" />
              <rect x="487" y="208" width="4" height="10" />
              <rect x="488.2" y="197" width="1.6" height="11" />
              <rect class="city-win" x="484" y="232" width="3.2" height="6" />
              <rect class="city-win" x="490" y="240" width="3.2" height="6" />
              <rect class="city-win" x="484" y="249" width="3.2" height="6" />
              <rect class="city-win" x="490" y="257" width="3.2" height="6" />
              <rect x="498" y="258" width="18" height="90" />
              <rect x="518" y="239" width="12" height="109" />
              <rect x="521" y="229" width="6" height="10" />
              <path d="M524 229 L520 221 L528 221 Z" />
              <rect x="523.2" y="209" width="1.6" height="11" />
              <rect x="532" y="254" width="16" height="94" />
              <rect class="city-win" x="535" y="258" width="4" height="6" />
              <rect class="city-win" x="541" y="264" width="4" height="6" />
              <rect class="city-win" x="535" y="271" width="4" height="6" />
              <rect x="550" y="244" width="10" height="104" />
              <rect x="552" y="235" width="6" height="10" />
              <rect x="562" y="263" width="14" height="85" />
              <rect x="578" y="272" width="8" height="76" />
            </g>
          </g>
          <g class="glass-door">
            <path class="door-handle" d="M450 224 H458" />
            <circle class="door-handle" cx="450" cy="224" r="1.8" />
          </g>
          <g class="furn">
            <rect class="room-seat-fill room-furn" x="504" y="322" width="60" height="8" rx="2.5" />
            <g class="room-furn">
              <line x1="514" y1="330" x2="510" y2="346" />
              <line x1="524" y1="330" x2="522" y2="348" />
              <line x1="544" y1="330" x2="546" y2="348" />
              <line x1="554" y1="330" x2="558" y2="346" />
            </g>
            <g class="camera-idle">
              <rect class="cam-body" x="516" y="304" width="32" height="18" rx="2.6" />
              <rect class="cam-body" x="526" y="298" width="12" height="6.5" rx="1" />
              <circle class="cam-lens" cx="532" cy="313" r="6.4" />
              <circle class="cam-lens-in" cx="532" cy="313" r="3.4" />
              <circle class="cam-glint" cx="530" cy="311" r="1.15" />
            </g>
          </g>
        </g>
        <path class="glyph gate" d="M394 68 H646 V348 H590 V124 H450 V348 H394 Z" />
        <g class="glyph four-b">
          <rect x="886" y="68" width="56" height="280" />
          <rect x="690" y="228" width="196" height="56" />
          <path d="M690 228 L886 68 L886 124 L762 228 Z" />
        </g>
        <g class="ink">
          <line class="ink-dash" x1="322" y1="68" x2="322" y2="348" />
          <line class="ink-dash" x1="98" y1="256" x2="350" y2="256" />
          <line class="ink-dash" x1="98" y1="228" x2="294" y2="68" />
          <line x1="316" y1="250" x2="328" y2="262" />
          <line x1="316" y1="262" x2="328" y2="250" />
          <line class="ink-dash" x1="914" y1="68" x2="914" y2="348" />
          <line class="ink-dash" x1="690" y1="256" x2="942" y2="256" />
          <line class="ink-dash" x1="690" y1="228" x2="886" y2="68" />
          <line x1="908" y1="250" x2="920" y2="262" />
          <line x1="908" y1="262" x2="920" y2="250" />
        </g>
        <g class="dim">
          <line x1="98" y1="36" x2="350" y2="36" />
          <line x1="98" y1="30" x2="98" y2="42" />
          <line x1="350" y1="30" x2="350" y2="42" />
          <line x1="394" y1="36" x2="646" y2="36" />
          <line x1="394" y1="30" x2="394" y2="42" />
          <line x1="646" y1="30" x2="646" y2="42" />
          <line x1="690" y1="36" x2="942" y2="36" />
          <line x1="690" y1="30" x2="690" y2="42" />
          <line x1="942" y1="30" x2="942" y2="42" />
          <line x1="28" y1="68" x2="28" y2="348" />
          <line x1="22" y1="68" x2="34" y2="68" />
          <line x1="22" y1="348" x2="34" y2="348" />
          <line x1="1012" y1="68" x2="1012" y2="348" />
          <line x1="1006" y1="68" x2="1018" y2="68" />
          <line x1="1006" y1="348" x2="1018" y2="348" />
        </g>
        <g class="tick">
          <line x1="210" y1="194" x2="222" y2="206" />
          <line x1="210" y1="206" x2="222" y2="194" />
          <line x1="514" y1="194" x2="526" y2="206" />
          <line x1="514" y1="206" x2="526" y2="194" />
          <line x1="818" y1="194" x2="830" y2="206" />
          <line x1="818" y1="206" x2="830" y2="194" />
        </g>
        <text class="label" x="224" y="28" text-anchor="middle">24′-0″</text>
        <text class="label" x="816" y="28" text-anchor="middle">24′-0″</text>
        <text class="label" x="16" y="214" text-anchor="middle" transform="rotate(-90 16 214)">18′-0″</text>
        <text class="label" x="1024" y="214" text-anchor="middle" transform="rotate(90 1024 214)">18′-0″</text>
      </svg>
    </div>
    <div class="copy">
      <h1>This page is under a different plan</h1>
      <p>It might have been moved, renamed, or doesn’t exist.</p>
      <div class="actions">
        <a class="cta" href="{{ $homeUrl }}">Go to Homepage <span aria-hidden="true">→</span></a>
        <a class="ghost" href="https://reprophotos.com/services/" target="_blank" rel="noopener noreferrer">Explore Our Services</a>
      </div>
    </div>
    <div class="foot">Spaces · People · Stories · Possibilities</div>
  </main>
@verbatim
  <script>
    const root = document.documentElement;
    const buttons = [...document.querySelectorAll(".chrome [data-theme]")];
    buttons.forEach((button) => {
      button.addEventListener("click", () => {
        root.dataset.theme = button.dataset.theme;
        buttons.forEach((item) => item.setAttribute("aria-pressed", String(item === button)));
      });
    });
  </script>
@endverbatim
</body>
</html>
