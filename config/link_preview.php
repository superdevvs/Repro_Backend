<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Link preview (Open Graph / Twitter card) generation
    |--------------------------------------------------------------------------
    |
    | Tour links are shared into WhatsApp, iMessage, Facebook, X, LinkedIn and
    | Slack. Those crawlers do not execute JavaScript, so the React SPA cannot
    | supply the preview: the metadata and the card image have to be produced
    | server side. This config drives both.
    |
    */

    'enabled' => env('LINK_PREVIEW_ENABLED', true),

    /*
    | Public base URL of the SPA. Used to build the canonical og:url that the
    | crawler should attribute the preview to, and the destination a human is
    | redirected to when they hit the prerendered HTML document.
    */
    'frontend_url' => env('LINK_PREVIEW_FRONTEND_URL', env('FRONTEND_URL', 'https://reprodashboard.com')),

    /*
    | Card geometry. 1200x630 (1.91:1) is the one size that renders as a
    | full-width card on every platform we care about. Anything that must
    | survive a platform-side crop belongs inside the safe area.
    */
    'card' => [
        'width' => 1200,
        'height' => 630,

        // WhatsApp silently drops an og:image over roughly 600KB, so the
        // encoder steps quality down until the payload fits this ceiling.
        'max_bytes' => 300 * 1024,
        'quality' => 82,
        'min_quality' => 62,

        // Centre region that no platform crops. Keep headlines inside it.
        'safe_area' => ['width' => 1080, 'height' => 600],
    ],

    /*
    | Which card design each shareable link type gets. Designs are implemented
    | in App\Services\LinkPreview\Cards. Every entry degrades on its own when
    | the shoot lacks the media the design needs - see LinkPreviewService.
    |
    |   d2 - hero photo + info bar (address, stats, price)
    |   d4 - gallery mosaic (hero + supporting tiles + photo count)
    |   d5 - cinematic video (poster, play affordance, runtime)
    |   d6 - 3D walkthrough (interior hero, 3D cue, floorplan inset)
    |   d8 - brand card (real REPRO logo; also the universal fallback)
    */
    'designs' => [
        'branded'       => 'd4',
        'mls'           => 'd2',
        'g-mls'         => 'd2',
        'video-branded' => 'd5',
        'video-mls'     => 'd5',
        'video-generic' => 'd5',
        '3d'            => 'd6', // legacy alias
        '3d-branded'    => 'd6',
        '3d-mls'        => 'd6',
        'dashboard'     => 'd8',
        'portal'        => 'd8',
    ],

    /*
    | The gallery mosaic needs a hero plus three supporting tiles to look
    | deliberate. Below this count it falls back to the single-hero card.
    */
    'mosaic_min_photos' => 4,

    /*
    | Unbranded link types. MLS rules require these to carry no agent name,
    | company, headshot, brokerage logo or phone number - and we keep the
    | REPRO wordmark off them too so the card is genuinely neutral.
    */
    'unbranded_types' => ['mls', 'g-mls', 'video-mls', 'video-generic', '3d-mls'],

    /*
    | Where generated cards are cached. The filename carries a hash of every
    | input, so a changed hero photo or price produces a new URL and the
    | crawler-side cache busts on its own instead of serving a stale card.
    */
    'cache' => [
        'path_prefix' => 'og-cards',
        'ttl_days' => 90,
        // File locks work without the application database and coordinate all
        // PHP workers on the shared backend host. Override for multi-host Redis.
        'lock_store' => env('LINK_PREVIEW_LOCK_STORE', 'file'),
        // Scoped per card fingerprint, never globally: Laravel waits for a lock
        // by sleeping in-process, so a long wait on a shared lock would pin PHP
        // workers. Keep the wait short enough that a queued request cannot hold
        // a worker for long, and the hold longer than a worst-case render.
        'lock_seconds' => 60,
        'lock_wait_seconds' => 10,
    ],

    /*
    | Ceiling on source images handed to GD. Decoding costs roughly 4 bytes per
    | pixel regardless of the compressed size, so megapixels are the limit that
    | matters - a 24MP delivered original is ~100MB in memory. Cards are 1200x630,
    | so nothing beyond this is useful detail.
    */
    'max_source_megapixels' => 40,

    /*
    | External image origins the renderer may read. Own storage bypasses HTTP.
    | Wildcards match subdomains only; redirects are intentionally disabled.
    */
    'remote_image_hosts' => [
        'i.ytimg.com',
        '*.vimeocdn.com',
        'youriguide.com',
        '*.youriguide.com',
    ],

    /*
    | Brand assets used by the cards. Paths are resolved relative to the
    | backend public/ directory.
    */
    'assets' => [
        'logo_light' => 'images/repro-logo.png',      // light lockup for dark cards
        'logo_dark'  => 'images/repro-logo-dark.png', // dark lockup for light cards
    ],

    /*
    | Inter matches the dashboard UI font. Bundled as static TTFs because GD
    | needs real TrueType files - the @fontsource packages ship woff2 only,
    | which FreeType cannot read. Falls back to system fonts if the bundle is
    | ever missing so card generation degrades instead of failing.
    */
    'fonts' => [
        // Searched in order: resources/fonts, then public/fonts.
        'dirs' => ['resources/fonts', 'public/fonts'],
        'weights' => [
            'extrabold' => 'Inter-ExtraBold.ttf',
            'bold' => 'Inter-Bold.ttf',
            'semibold' => 'Inter-SemiBold.ttf',
            'medium' => 'Inter-Medium.ttf',
        ],
        'fallbacks' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\segoeuib.ttf',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
        ],
    ],

    /*
    | Brand palette, taken from the logo artwork and the SPA theme-color.
    */
    'palette' => [
        'red' => '#c8102e',
        'blue' => '#0b6bc9',
        'ink' => '#060a0e',
        'accent' => '#1463ff',
        'video' => '#dc2626',
        'tour3d' => '#7c3aed',
    ],
];
