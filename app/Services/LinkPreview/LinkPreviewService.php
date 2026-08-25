<?php

namespace App\Services\LinkPreview;

use App\Models\Shoot;
use App\Services\Shoots\ShootPublicAssetsService;

/**
 * Turns a shareable link into the preview that represents it.
 *
 * All tour data comes from ShootPublicAssetsService, the same service that
 * feeds the tour pages themselves. That is deliberate: a preview must never
 * reveal more than the page it links to. It also means the hero photo, property
 * details and video poster are resolved by exactly one piece of code, so a
 * preview cannot drift from the tour it advertises.
 */
class LinkPreviewService
{
    /** Link types that map to a real shoot. */
    public const TOUR_TYPES = [
        'branded',
        'mls',
        'g-mls',
        'video-branded',
        'video-mls',
        'video-generic',
        '3d',
        '3d-branded',
        '3d-mls',
    ];

    /** Link types that are not tied to a shoot. */
    public const STATIC_TYPES = ['dashboard', 'portal'];

    public const THREE_D_PROVIDERS = ['matterport', 'iguide', 'zillow'];

    public function __construct(
        private readonly ShootPublicAssetsService $assets,
    ) {
    }

    public function isTourType(string $type): bool
    {
        return in_array($type, self::TOUR_TYPES, true);
    }

    public function isKnownType(string $type): bool
    {
        return $this->isTourType($type) || in_array($type, self::STATIC_TYPES, true);
    }

    /**
     * Build the preview for a shoot-backed link.
     */
    public function forShoot(Shoot $shoot, string $type, ?string $provider = null): PreviewPayload
    {
        $provider = $this->normalizeProvider($provider);
        // The MLS/generic pages are driven by the same three payload shapes the
        // tour API exposes, so a video link resolves against its parent page.
        // Previews never read payment state, and reconciling it calls Stripe
        // (~5.5s on a shoot with an open balance). That latency made crawlers
        // time out and cache the generic fallback card instead of the property.
        $assets = $this->assets->buildTypedPublicAssets(
            $shoot,
            $this->assetTypeFor($type),
            reconcilePayments: false
        );

        $branded = !in_array($type, (array) config('link_preview.unbranded_types', []), true);

        $photos = $this->imageList($assets['hero_photos'] ?? [], $assets['photos'] ?? []);
        $floorplan = $this->firstFloorplanImage($assets['floorplans'] ?? null);
        $videoVisible = $this->videoIsPubliclyVisible($shoot, $assets);
        $videoPoster = $videoVisible ? ($assets['video_thumbnail_url'] ?? null) : null;
        $has3d = $this->has3dTour($assets);

        $details = is_array($assets['property_details'] ?? null) ? $assets['property_details'] : [];
        $stats = $this->buildStats($details);
        $price = $this->cleanPrice($details['price'] ?? null);

        $design = $this->chooseDesign($type, [
            'photos' => count($photos),
            'video' => $videoVisible,
            'video_image' => $videoPoster ?? ($photos[0] ?? null),
            'has_3d' => $has3d,
            'floorplan' => $floorplan,
        ]);

        $hero = $this->chooseHero($design, $photos, $videoPoster, $floorplan);
        $gallery = $this->chooseGallery($design, $photos, $hero, $floorplan);

        $addressLine = $this->trimOrNull($shoot->address);
        $cityLine = $this->buildCityLine($shoot);

        $agentName = $branded ? $this->trimOrNull($assets['shoot']['client_name'] ?? null) : null;
        $agentCompany = $branded ? $this->trimOrNull($assets['shoot']['client_company'] ?? null) : null;

        $capabilities = [
            'photos' => count($photos),
            'floorplan' => $floorplan !== null,
            'video' => $videoVisible,
            '3d' => $has3d,
        ];

        return new PreviewPayload(
            type: $type,
            design: $design,
            branded: $branded,
            title: $this->buildTitle($type, $design, $shoot, $addressLine, $cityLine),
            description: $this->buildDescription($type, $design, $branded, $stats, $price, $shoot, $capabilities, $agentName, $agentCompany, $details),
            url: $this->canonicalUrl($type, $shoot, $provider),
            hero: $hero,
            gallery: $gallery,
            floorplan: $floorplan,
            addressLine: $addressLine,
            cityLine: $cityLine,
            stats: $stats,
            price: $price,
            mlsId: $this->trimOrNull($details['mls_id'] ?? null),
            photoCount: count($photos),
            chipLabel: $this->buildChipLabel($type, $design, count($photos), $capabilities),
            chipColor: $this->chipColorFor($design),
            agentName: $agentName,
            agentCompany: $agentCompany,
            headline: $design === 'd8' ? $this->fallbackHeadline($type, $addressLine) : null,
            subhead: $design === 'd8' ? $this->fallbackSubhead($type, $capabilities) : null,
            videoUrl: $videoVisible ? ($assets['video_link'] ?? null) : null,
            shootId: (int) $shoot->id,
            fingerprintSeed: $this->fingerprintSeed($shoot),
        );
    }

    /**
     * Build the preview for a link that is not tied to a shoot: the dashboard
     * root and the client portal.
     */
    public function forStaticPage(string $type): PreviewPayload
    {
        $isPortal = $type === 'portal';

        return new PreviewPayload(
            type: $type,
            design: 'd8',
            branded: false,
            title: $isPortal
                ? 'Client Portal - R/E Pro Photos'
                : 'R/E Pro Photos - Client Dashboard',
            description: $isPortal
                ? 'Browse listings, photo tours, video, floor plans and downloads in one place.'
                : 'Book real estate photography, download delivered media, and manage listings, floor plans and invoices.',
            url: $this->canonicalUrl($type, null),
            headline: $isPortal ? 'Client Portal' : 'Client Dashboard',
            subhead: $isPortal
                ? 'Listings, photo tours, video, floor plans and downloads in one place.'
                : 'Book shoots, download media, manage listings, floor plans and invoices.',
        );
    }

    // ---------------------------------------------------------------------
    // Design selection
    // ---------------------------------------------------------------------

    /**
     * Pick the card design, degrading through the alternatives when the shoot
     * lacks the media a design needs. Every branch ends somewhere valid, so a
     * sparse shoot still gets a deliberate-looking card instead of a broken one.
     *
     * @param  array{photos: int, video: bool, video_image: string|null, has_3d: bool, floorplan: string|null}  $ctx
     */
    private function chooseDesign(string $type, array $ctx): string
    {
        $configured = (string) (config("link_preview.designs.{$type}") ?? 'd2');

        // Video links: the cinematic card only makes sense when the video is
        // actually watchable and we have something to draw the play button on.
        if ($configured === 'd5') {
            if ($ctx['video'] && $ctx['video_image']) {
                return 'd5';
            }
            // Undelivered or provider gave us no poster: fall back to the
            // property card so the link still previews as a listing.
            $configured = $ctx['photos'] >= (int) config('link_preview.mosaic_min_photos', 4) ? 'd4' : 'd2';
        }

        // 3D links need the walkthrough to exist.
        if ($configured === 'd6' && !$ctx['has_3d']) {
            $configured = 'd2';
        }

        if ($configured === 'd4' && $ctx['photos'] < (int) config('link_preview.mosaic_min_photos', 4)) {
            $configured = 'd2';
        }

        if (in_array($configured, ['d2', 'd4'], true) && $ctx['photos'] < 1) {
            // No photography at all. An iGUIDE-only shoot can still show the
            // 3D card; otherwise the brand card is the honest answer.
            return $ctx['has_3d'] && $ctx['floorplan'] ? 'd6' : 'd8';
        }

        return $configured;
    }

    /**
     * @param  array<int, string>  $photos
     */
    private function chooseHero(string $design, array $photos, ?string $videoPoster, ?string $floorplan): ?string
    {
        if ($design === 'd5') {
            // Prefer real photography over a provider thumbnail: YouTube's
            // hqdefault is 480x360 and letterboxed, which looks poor blown up
            // to 1200x630. The poster is the fallback, not the first choice.
            return $photos[0] ?? $videoPoster;
        }

        if ($design === 'd6') {
            // Interior reads as "step inside" better than a front elevation.
            return $photos[1] ?? $photos[0] ?? $floorplan;
        }

        if ($design === 'd8') {
            return $photos[0] ?? null;
        }

        return $photos[0] ?? null;
    }

    /**
     * Supporting tiles for the mosaic, hero excluded and de-duplicated.
     *
     * @param  array<int, string>  $photos
     * @return array<int, string>
     */
    private function chooseGallery(string $design, array $photos, ?string $hero, ?string $floorplan): array
    {
        if ($design === 'd4') {
            $rest = array_values(array_filter($photos, fn ($p) => $p !== $hero));

            return array_slice($rest, 0, 3);
        }

        if ($design === 'd8') {
            $rest = array_values(array_filter($photos, fn ($p) => $p !== null));

            return array_slice($rest, 0, 5);
        }

        return [];
    }

    // ---------------------------------------------------------------------
    // Gates
    // ---------------------------------------------------------------------

    /**
     * Video is withheld from the public tour payload until the shoot is
     * delivered - see ShootPublicAssetsController::canViewVideoAssets. A
     * crawler is always anonymous, so "delivered" is the whole test here.
     * Without this, an unlisted walkthrough could be cached into a public
     * preview card before the client has even seen it.
     */
    private function videoIsPubliclyVisible(Shoot $shoot, array $assets): bool
    {
        if (empty($assets['video_link'])) {
            return false;
        }

        return $shoot->status === Shoot::STATUS_DELIVERED
            || $shoot->workflow_status === Shoot::STATUS_DELIVERED;
    }

    private function has3dTour(array $assets): bool
    {
        foreach (['matterport_url', 'iguide_tour_url', 'iguide_url'] as $key) {
            if (!empty($assets[$key])) {
                return true;
            }
        }

        $tourLinks = is_array($assets['tour_links'] ?? null) ? $assets['tour_links'] : [];

        return !empty($tourLinks['zillow_3d']);
    }

    // ---------------------------------------------------------------------
    // Copy
    // ---------------------------------------------------------------------

    private function buildTitle(string $type, string $design, Shoot $shoot, ?string $address, ?string $cityLine): string
    {
        if (!$address) {
            return $this->isUnbrandedType($type)
                ? 'Property Tour'
                : 'Property Tour - R/E Pro Photos';
        }

        // Unbranded links get the plain postal address: no marketing label that
        // could read as agent or vendor promotion.
        if ($this->isUnbrandedType($type)) {
            return $cityLine ? "{$address}, {$cityLine}" : $address;
        }

        return match (true) {
            $design === 'd5' => "{$address} - Video Tour",
            $design === 'd6' => "{$address} - 3D Walkthrough",
            $type === 'video-mls', $type === 'video-generic' => "{$address} - Video Tour",
            $design === 'd8' => "{$address} - Tour Coming Soon",
            default => "{$address} - Virtual Tour",
        };
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $stats
     * @param  array{photos: int, floorplan: bool, video: bool, 3d: bool}  $capabilities
     */
    private function buildDescription(
        string $type,
        string $design,
        bool $branded,
        array $stats,
        ?string $price,
        Shoot $shoot,
        array $capabilities,
        ?string $agentName,
        ?string $agentCompany,
        array $details
    ): string {
        $parts = [];

        $statText = $this->statSentence($stats);
        if ($statText !== '') {
            $parts[] = $statText;
        }
        if ($price) {
            $parts[] = $price;
        }

        $cityLine = $this->buildCityLine($shoot);
        if ($cityLine) {
            $parts[] = $cityLine;
        }

        $lead = $parts ? implode(' - ', $parts) . '.' : '';

        // Only advertise media the shoot actually has.
        $included = [];
        if ($design === 'd5') {
            $included[] = 'Watch the full walkthrough';
        }
        if ($capabilities['photos'] > 0) {
            $included[] = $capabilities['photos'] . ' photos';
        }
        if ($capabilities['3d']) {
            $included[] = '3D walkthrough';
        }
        if ($capabilities['floorplan']) {
            $included[] = 'floor plans';
        }
        if ($capabilities['video'] && $design !== 'd5') {
            $included[] = 'video';
        }

        $sentences = array_filter([$lead, $included ? ucfirst(implode(', ', $included)) . '.' : '']);

        if ($branded && $agentName) {
            $presenter = $agentCompany ? "{$agentName}, {$agentCompany}" : $agentName;
            $sentences[] = "Presented by {$presenter}.";
        }

        $description = trim(implode(' ', $sentences));

        if ($description === '') {
            $description = $branded
                ? 'Real estate photo tour by R/E Pro Photos.'
                : 'View the property tour and available media.';
        }

        // Platforms trim hard; keep it inside a length every one of them shows.
        return $this->clamp($description, 200);
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $stats
     */
    private function statSentence(array $stats): string
    {
        $map = ['BEDS' => 'bd', 'BATHS' => 'ba', 'SQ FT' => 'sqft'];
        $out = [];

        foreach ($stats as $stat) {
            if (isset($map[$stat['label']])) {
                $out[] = $stat['value'] . ' ' . $map[$stat['label']];
            }
        }

        return implode(' ', $out);
    }

    private function buildChipLabel(string $type, string $design, int $photoCount, array $capabilities): ?string
    {
        if ($design === 'd8') {
            return null;
        }

        if ($design === 'd5') {
            return 'VIDEO TOUR';
        }

        if ($design === 'd6') {
            return '3D WALKTHROUGH';
        }

        if ($design === 'd4') {
            $label = $photoCount . ' PHOTOS';
            if ($capabilities['floorplan']) {
                $label .= ' - FLOOR PLANS';
            }

            return $label;
        }

        // Every unbranded type, not just mls/g-mls. video-mls, video-generic and
        // 3d-mls all land here when their media is missing, and they were being
        // labelled "VIRTUAL TOUR" while the plain MLS card said "PROPERTY TOUR".
        return $this->isUnbrandedType($type) ? 'PROPERTY TOUR' : 'VIRTUAL TOUR';
    }

    private function chipColorFor(string $design): ?string
    {
        return match ($design) {
            'd5' => (string) config('link_preview.palette.video'),
            'd6' => (string) config('link_preview.palette.tour3d'),
            'd8' => null,
            default => (string) config('link_preview.palette.accent'),
        };
    }

    private function fallbackHeadline(string $type, ?string $address): string
    {
        if ($address) {
            return $address;
        }

        return 'Property Tour';
    }

    private function fallbackSubhead(string $type, array $capabilities): string
    {
        if ($capabilities['photos'] === 0 && !$capabilities['3d']) {
            return 'This tour is being prepared. Your photographer will share it the moment it is ready.';
        }

        return 'View the full property tour, floor plans and downloads.';
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The tour API exposes three payload shapes. Video links and the 3D link
     * hang off one of those pages rather than having their own.
     */
    private function assetTypeFor(string $type): string
    {
        return match ($type) {
            'mls', 'video-mls', '3d-mls' => 'mls',
            'g-mls', 'video-generic' => 'generic-mls',
            default => 'branded',
        };
    }

    public function canonicalUrl(string $type, ?Shoot $shoot, ?string $provider = null): string
    {
        $base = rtrim((string) config('link_preview.frontend_url'), '/');

        if ($type === 'dashboard') {
            return $base . '/';
        }
        if ($type === 'portal') {
            return $base . '/client-portal';
        }

        $path = match ($type) {
            'mls' => '/tour/mls',
            'g-mls' => '/tour/g-mls',
            'video-branded' => '/tour/video/branded',
            'video-mls' => '/tour/video/mls',
            'video-generic' => '/tour/video/generic',
            '3d', '3d-branded' => '/tour/3d/branded',
            '3d-mls' => '/tour/3d/mls',
            default => '/tour/branded',
        };

        $query = [];
        if ($shoot !== null) {
            $query['shootId'] = (string) $shoot->id;
        }
        if (in_array($type, ['3d', '3d-branded', '3d-mls'], true)
            && ($provider = $this->normalizeProvider($provider)) !== null) {
            $query['provider'] = $provider;
        }

        return $base . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * Cover photos first, then the rest of the gallery in delivery order,
     * de-duplicated. Mirrors what the tour page shows.
     *
     * @return array<int, string>
     */
    private function imageList(array $heroPhotos, array $photos): array
    {
        $merged = array_merge(
            array_values(array_filter($heroPhotos, 'is_string')),
            array_values(array_filter($photos, 'is_string'))
        );

        return array_values(array_unique(array_filter($merged, fn ($u) => trim($u) !== '')));
    }

    private function firstFloorplanImage(mixed $floorplans): ?string
    {
        if (!is_array($floorplans)) {
            return null;
        }

        foreach ($floorplans as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                return $entry;
            }
            if (is_array($entry)) {
                foreach (['image', 'preview', 'url', 'thumbnail'] as $key) {
                    if (!empty($entry[$key]) && is_string($entry[$key])) {
                        return $entry[$key];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Only the stats the shoot actually has. beds/baths/sqft/lot are optional
     * free-form JSON on the shoot, so any of them can be missing.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function buildStats(array $details): array
    {
        $candidates = [
            ['BEDS', $details['beds'] ?? null],
            ['BATHS', $details['baths'] ?? null],
            ['SQ FT', $details['sqft'] ?? null],
            ['LOT', $details['lot_size'] ?? null],
        ];

        $stats = [];
        foreach ($candidates as [$label, $value]) {
            $value = $this->trimOrNull(is_scalar($value) ? (string) $value : null);
            if ($value === null) {
                continue;
            }

            if ($label === 'SQ FT' && is_numeric(str_replace(',', '', $value))) {
                $value = number_format((float) str_replace(',', '', $value));
            }

            $stats[] = ['label' => $label, 'value' => $value];
        }

        return $stats;
    }

    private function cleanPrice(mixed $price): ?string
    {
        $price = $this->trimOrNull(is_scalar($price) ? (string) $price : null);
        if ($price === null) {
            return null;
        }

        // Accept both "1495000" and "$1,495,000" as stored.
        $numeric = str_replace([',', '$', ' '], '', $price);
        if (is_numeric($numeric)) {
            return '$' . number_format((float) $numeric);
        }

        return $price;
    }

    private function buildCityLine(Shoot $shoot): ?string
    {
        $city = $this->trimOrNull($shoot->city);
        $state = $this->trimOrNull($shoot->state);
        $zip = $this->trimOrNull($shoot->zip);

        $left = implode(', ', array_filter([$city, $state]));
        $line = trim($left . ' ' . (string) $zip);

        return $line === '' ? null : $line;
    }

    public function normalizeProvider(?string $provider): ?string
    {
        $provider = strtolower(trim((string) $provider));

        return in_array($provider, self::THREE_D_PROVIDERS, true) ? $provider : null;
    }

    private function isUnbrandedType(string $type): bool
    {
        return in_array($type, (array) config('link_preview.unbranded_types', []), true);
    }

    /**
     * Version the immutable card by the media records as well as the shoot.
     * Image processing commonly rewrites a stable object key while touching
     * only ShootFile, so the parent shoot timestamp alone is insufficient.
     */
    private function fingerprintSeed(Shoot $shoot): string
    {
        $files = $shoot->relationLoaded('files')
            ? $shoot->files
            : $shoot->files()->get();

        $fileVersions = $files
            ->sortBy('id')
            ->map(static fn ($file): array => [
                'id' => (int) $file->id,
                'updated' => $file->updated_at?->format('U.u'),
                'processed' => $file->processed_at?->format('U.u'),
                'size' => $file->file_size,
                'storage' => $file->storage_path,
                'web' => $file->web_path,
                'watermarked_web' => $file->watermarked_web_path,
                'cover' => (bool) $file->is_cover,
                'order' => $file->sort_order,
                'stage' => $file->workflow_stage,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'shoot' => $shoot->updated_at?->format('U.u'),
            'files' => $fileVersions,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1), " ,.-") . '...';
    }
}
