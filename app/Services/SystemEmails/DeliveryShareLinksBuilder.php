<?php

namespace App\Services\SystemEmails;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Support\Facades\Log;

/**
 * Builds the forwardable client delivery link set used in payment/delivery emails.
 *
 * Labels match the legacy reprophotos-style share pack (MLS zip, full zip, MLS
 * tour, branded tour, optional video / Zillow / property page).
 *
 * Share links are omitted while public release is locked (unpaid and no
 * bypass_paywall) — same gate as EmailShootPhotos / public tours.
 */
class DeliveryShareLinksBuilder
{
    public function __construct(
        protected ShootMediaArchiveService $archives,
        protected MediaStorage $media,
        protected ShootClientReleaseAccessService $releaseAccess,
    ) {
    }

    /**
     * @return array{
     *     links: list<array{key:string,label:string,url:string}>,
     *     property_url:?string,
     *     completed_shoots_note:string,
     *     has_links:bool
     * }
     */
    public function forShoot(Shoot $shoot): array
    {
        return $this->forShootSummary($shoot);
    }

    /** Forwardable ZIPs for finalized segments, with scope and approval signed into each URL. */
    public function forDeliveredServices(Shoot $shoot, array $serviceItemIds): array
    {
        if ($this->releaseAccess->isPublicReleaseLocked($shoot)) {
            return $this->emptyShare();
        }
        $links = [];
        $items = $shoot->serviceItems()->with('service')->whereIn('id', $serviceItemIds)
            ->where('workflow_status', 'delivered')->where('delivery_status', 'delivered')->orderBy('id')->get();
        foreach ($items as $item) {
            $variants = [
                ['photos', 'small', 'Small/MLS-Size Images Download'],
                ['photos', 'original', 'Full-Size Images Download'],
                ['floorplans', 'original', 'Floor Plans Download'],
            ];
            if ($item->service?->supportsVideoIntake()) {
                $variants[] = ['videos', 'original', 'Video Download'];
            }
            foreach ($variants as [$asset, $size, $label]) {
                $type = 'edited|asset='.$asset.';verified';
                if (! $this->archives->hasDownloadableFiles($shoot, $type, $size, (int) $item->id)) {
                    continue;
                }
                $links[] = [
                    'key' => 'service_'.$item->id.'_'.$asset.'_'.$size,
                    'label' => ($item->service?->name ?? 'Delivered service').': '.$label,
                    'url' => $this->archives->buildCanonicalPublicDownloadUrl($shoot, $type, $size, null, (int) $item->id),
                ];
            }
        }

        return array_replace($this->emptyShare(), [
            'links' => $links,
            'has_links' => $links !== [],
            'completed_shoots_note' => 'These downloads contain the finalized services listed above. Open your shoot in the dashboard for its latest progress.',
        ]);
    }

    /**
     * Build the paid Shoot Summary's link pack from released media and saved URLs.
     * Unlike the older delivery email, a URL for a tour is not invented when no
     * photo or tour destination has been saved.
     *
     * @return array<string, mixed>
     */
    public function forShootSummary(Shoot $shoot): array
    {
        if ($this->releaseAccess->isPublicReleaseLocked($shoot)) {
            return $this->emptyShare() + ['additional_links' => []];
        }

        $tourLinks = $this->normalizeTourLinks($shoot->tour_links ?? null);
        $hasBookedVideo = $this->hasBookedVideo($shoot);
        if (! $hasBookedVideo) {
            $tourLinks = array_diff_key($tourLinks, array_flip(['video_mls', 'video_branded', 'video_generic', 'video_link']));
        }
        $small = $this->firstHttpUrl($this->safeArchiveUrl($shoot, 'edited|verified', 'small'));
        $full = $this->firstHttpUrl($this->safeArchiveUrl($shoot, 'edited|verified', 'original'));
        $floorplans = $this->firstHttpUrl($this->safeArchiveUrl($shoot, 'edited|asset=floorplans;verified', 'original'));
        $hasPhotos = $small !== null || $full !== null;
        $package = app(\App\Services\Messaging\ShootDeliveryRevision::class)->publicOfflinePackage($shoot);
        $hasVideoEmbed = $hasBookedVideo && collect($tourLinks['embeds'] ?? [])->contains(fn ($embed) => is_array($embed)
            && $this->firstHttpUrl($embed['url'] ?? null, $embed['mls'] ?? null, $embed['branded'] ?? null) !== null);
        $mlsTour = $this->firstHttpUrl(
            $tourLinks['mls'] ?? null,
            $tourLinks['generic_mls'] ?? null,
            $tourLinks['genericMls'] ?? null,
            $tourLinks['iguide_mls'] ?? null,
            $tourLinks['matterport_mls'] ?? null,
            $tourLinks['cubicasa_mls'] ?? null,
            $hasPhotos || $hasVideoEmbed || in_array('mls', $package['audiences'] ?? [], true) ? $this->publicTourUrl($shoot->id, 'mls') : null,
        );
        $brandedTour = $this->firstHttpUrl(
            $tourLinks['branded'] ?? null,
            $tourLinks['iguide_branded'] ?? null,
            $tourLinks['iGuide'] ?? null,
            $tourLinks['iguide'] ?? null,
            $shoot->iguide_tour_url ?? null,
            $tourLinks['matterport_branded'] ?? null,
            $tourLinks['matterport'] ?? null,
            $tourLinks['cubicasa_branded'] ?? null,
            $hasPhotos || $hasVideoEmbed || in_array('branded', $package['audiences'] ?? [], true) ? $this->publicTourUrl($shoot->id, 'branded') : null,
        );
        $propertyUrl = $this->firstHttpUrl(
            $tourLinks['property_url'] ?? null,
            $tourLinks['property_link'] ?? null,
            $brandedTour,
            $mlsTour,
        );
        $videoUrl = $hasBookedVideo ? $this->summaryVideoDownloadUrl($shoot, $tourLinks) : null;
        $zillow = $this->firstHttpUrl($tourLinks['zillow_3d'] ?? null);

        $links = [];
        foreach ([
            ['small_zip', 'Small/MLS-Size Images Download', $small],
            ['full_zip', 'Full-Size Images Download', $full],
            ['floorplans', 'Floor Plans Download', $floorplans],
            ['mls_tour', 'MLS-Compliant Tour (non-branded)', $mlsTour],
            ['branded_tour', 'Branded Tour', $brandedTour],
            ['property', 'Property / Address Link', $propertyUrl],
            ['video', 'Video Download', $videoUrl],
            ['zillow_3d', 'Zillow 3D Home Tour', $zillow],
        ] as [$key, $label, $url]) {
            if ($url !== null) {
                $links[] = compact('key', 'label', 'url');
            }
        }

        $additional = $this->summaryAdditionalLinks($tourLinks, array_column($links, 'url'));

        return [
            'links' => array_merge($links, $additional),
            'additional_links' => $additional,
            'property_url' => $propertyUrl,
            'completed_shoots_note' => 'You can also sign in to your client account and open this shoot under Completed Shoots.',
            'has_links' => $links !== [] || $additional !== [],
            'small_zip_link' => $small,
            'full_zip_link' => $full,
            'floorplans_link' => $floorplans,
            'mls_tour_link' => $mlsTour,
            'branded_tour_link' => $brandedTour,
            'video_download_link' => $videoUrl,
            'zillow_3d_link' => $zillow,
        ];
    }

    /** @return list<array{key:string,label:string,url:string}> */
    private function summaryAdditionalLinks(array $tourLinks, array $usedUrls): array
    {
        $labels = [
            'video_mls' => 'MLS Video',
            'video_branded' => 'Branded Video',
            'video_generic' => 'Property Video',
            'video_link' => 'Property Video',
            'matterport_mls' => 'Matterport 3D Tour (MLS)',
            'matterport_branded' => 'Matterport 3D Tour (Branded)',
            'matterport' => 'Matterport 3D Tour',
            'iguide_mls' => 'iGUIDE 3D Tour (MLS)',
            'iguide_branded' => 'iGUIDE 3D Tour (Branded)',
            'iGuide' => 'iGUIDE 3D Tour',
            'iguide' => 'iGUIDE 3D Tour',
            'cubicasa_mls' => 'CubiCasa Tour (MLS)',
            'cubicasa_branded' => 'CubiCasa Tour (Branded)',
            'cubicasa_url' => 'CubiCasa Floor Plan',
            'slideshow' => 'Property Slideshow',
            'slideshow_url' => 'Property Slideshow',
            'neo_tour' => 'Property Tour',
            'neotour' => 'Property Tour',
        ];

        $additional = [];
        foreach ($labels as $key => $label) {
            $url = $this->firstHttpUrl($tourLinks[$key] ?? null);
            if ($url === null || in_array($url, $usedUrls, true)) {
                continue;
            }
            $additional[] = ['key' => 'additional_'.$key, 'label' => $label, 'url' => $url];
            $usedUrls[] = $url;
        }

        return $additional;
    }

    private function summaryVideoDownloadUrl(Shoot $shoot, array $tourLinks): ?string
    {
        foreach (['video_mls', 'video_branded', 'video_generic', 'video_link'] as $key) {
            $url = $this->firstHttpUrl($tourLinks[$key] ?? null);
            if ($url !== null && preg_match('/\.mp4([?#]|$)/i', $url)) {
                return $url;
            }
        }

        // Re-query ownership even when files are loaded: a line may have been reopened.
        $files = $shoot->files()->where('media_type', 'video')->verifiedForFinalizedDelivery()->orderBy('id')->get();

        foreach ($files as $file) {
            if (strtolower((string) $file->media_type) !== 'video'
                || ! in_array($file->workflow_stage, [ShootFile::STAGE_VERIFIED], true)
                || $file->is_hidden || $file->isBlockedFromDelivery() || $file->isIguideOfflinePackage()) {
                continue;
            }
            foreach (['path', 'storage_path', 'web_path'] as $field) {
                $raw = $file->{$field} ?? null;
                $url = $this->firstHttpUrl($raw);
                if ($url !== null && preg_match('/\.mp4([?#]|$)/i', $url)) {
                    return $url;
                }
                if (! is_string($raw) || preg_match('/^[a-z][a-z0-9+.-]*:/i', $raw)) {
                    continue;
                }
                $storageKey = $this->media->normalizeKey($raw);
                if ($storageKey && $this->media->exists($storageKey)) {
                    $url = $this->firstHttpUrl($this->media->publicUrl($storageKey));
                    if ($url !== null) {
                        return $url;
                    }
                }
            }
        }

        return null;
    }

    private function firstHttpUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $url = trim($value);
            if (filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     links: list<array{key:string,label:string,url:string}>,
     *     property_url:null,
     *     completed_shoots_note:string,
     *     has_links:bool,
     *     small_zip_link:null,
     *     full_zip_link:null,
     *     mls_tour_link:null,
     *     branded_tour_link:null,
     *     video_download_link:null,
     *     zillow_3d_link:null
     * }
     */
    private function emptyShare(): array
    {
        return [
            'links' => [],
            'property_url' => null,
            'completed_shoots_note' => '',
            'has_links' => false,
            'small_zip_link' => null,
            'full_zip_link' => null,
            'floorplans_link' => null,
            'mls_tour_link' => null,
            'branded_tour_link' => null,
            'video_download_link' => null,
            'zillow_3d_link' => null,
        ];
    }

    private function safeArchiveUrl(Shoot $shoot, string $type, string $size): ?string
    {
        try {
            if (!$this->archives->hasDownloadableFiles($shoot, $type, $size)) {
                return null;
            }

            return $this->archives->buildPublicDownloadUrl($shoot, $type, $size);
        } catch (\Throwable $e) {
            Log::warning('Delivery share link archive URL skipped', [
                'shoot_id' => $shoot->id,
                'type' => $type,
                'size' => $size,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A saved video URL or stray file is not proof that a video was ordered. */
    private function hasBookedVideo(Shoot $shoot): bool
    {
        $items = $shoot->serviceItems()->with('service')->get();
        if ($items->isNotEmpty()) {
            return $items->contains(fn ($item) => $item->workflow_status === 'delivered'
                && $item->delivery_status === 'delivered' && ($item->service?->supportsVideoIntake() ?? false));
        }

        $service = $shoot->relationLoaded('service') ? $shoot->service : $shoot->service()->first();

        return $service?->supportsVideoIntake() ?? false;
    }

    private function publicTourUrl(int|string $shootId, string $type): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        return $frontendUrl.'/tour/'.$type.'?shootId='.urlencode((string) $shootId);
    }

    private function normalizeTourLinks(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function firstUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $candidate = trim($value);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }
}
