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
        // Single gate for every email that embeds the share-link block.
        if ($this->releaseAccess->isPublicReleaseLocked($shoot)) {
            return $this->emptyShare();
        }

        $tourLinks = $this->normalizeTourLinks($shoot->tour_links ?? null);
        $links = [];

        $small = $this->safeArchiveUrl($shoot, 'edited', 'small');
        if ($small) {
            $links[] = [
                'key' => 'small_zip',
                'label' => 'Small/MLS-Size Images Download',
                'url' => $small,
            ];
        }

        $full = $this->safeArchiveUrl($shoot, 'edited', 'original');
        if ($full) {
            $links[] = [
                'key' => 'full_zip',
                'label' => 'Full-Size Images Download',
                'url' => $full,
            ];
        }

        $mlsTour = $this->firstUrl(
            $tourLinks['mls'] ?? null,
            $tourLinks['generic_mls'] ?? null,
            $tourLinks['genericMls'] ?? null,
            $tourLinks['iguide_mls'] ?? null,
            $this->publicTourUrl($shoot->id, 'mls'),
            $this->publicTourUrl($shoot->id, 'generic-mls'),
        );
        if ($mlsTour) {
            $links[] = [
                'key' => 'mls_tour',
                'label' => 'MLS-Compliant Tour (non-branded)',
                'url' => $mlsTour,
            ];
        }

        $brandedTour = $this->firstUrl(
            $tourLinks['branded'] ?? null,
            $tourLinks['iguide_branded'] ?? null,
            $tourLinks['iGuide'] ?? null,
            $tourLinks['iguide'] ?? null,
            $shoot->iguide_tour_url ?? null,
            $this->publicTourUrl($shoot->id, 'branded'),
        );
        if ($brandedTour) {
            $links[] = [
                'key' => 'branded_tour',
                'label' => 'Branded Tour',
                'url' => $brandedTour,
            ];
        }

        $propertyUrl = $this->firstUrl(
            $brandedTour,
            $mlsTour,
            $this->publicTourUrl($shoot->id, 'branded'),
        );
        if ($propertyUrl) {
            $address = trim(implode(', ', array_filter([
                $shoot->address,
                trim(implode(' ', array_filter([$shoot->city, $shoot->state]))),
                $shoot->zip,
            ])));
            $links[] = [
                'key' => 'property',
                'label' => $address !== '' ? $address : 'Property / Address Link',
                'url' => $propertyUrl,
            ];
        }

        $videoUrl = $this->resolveVideoDownloadUrl($shoot, $tourLinks);
        if ($videoUrl) {
            $links[] = [
                'key' => 'video',
                'label' => 'Video Download',
                'url' => $videoUrl,
            ];
        }

        $zillow = $this->firstUrl($tourLinks['zillow_3d'] ?? null);
        if ($zillow) {
            $links[] = [
                'key' => 'zillow_3d',
                'label' => 'Zillow 3D',
                'url' => $zillow,
            ];
        }

        // Deduplicate identical URLs while keeping first label (MLS zip vs full
        // may legitimately share hosts but not paths; property may match branded).
        $seen = [];
        $deduped = [];
        foreach ($links as $link) {
            $url = $link['url'];
            // Allow property to reuse branded/mls tour URL with its own label.
            $dedupeKey = $link['key'] === 'property' ? 'property:'.$url : $url;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $deduped[] = $link;
        }

        return [
            'links' => $deduped,
            'property_url' => $propertyUrl,
            'completed_shoots_note' => 'You can also sign in to your client account and open this shoot under Completed Shoots.',
            'has_links' => $deduped !== [],
            // Convenience keys for template variables / overrides
            'small_zip_link' => $small,
            'full_zip_link' => $full,
            'mls_tour_link' => $mlsTour,
            'branded_tour_link' => $brandedTour,
            'video_download_link' => $videoUrl,
            'zillow_3d_link' => $zillow,
        ];
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

    private function resolveVideoDownloadUrl(Shoot $shoot, array $tourLinks): ?string
    {
        foreach (['video_mls', 'video_branded', 'video_generic', 'video_link'] as $key) {
            $candidate = $tourLinks[$key] ?? null;
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate !== '' && preg_match('/\.mp4([?#]|$)/i', $candidate)) {
                return $candidate;
            }
        }

        $video = $shoot->relationLoaded('files')
            ? $shoot->files->first(fn (ShootFile $file) => strtolower((string) $file->media_type) === 'video')
            : $shoot->files()
                ->where('media_type', 'video')
                ->whereIn('workflow_stage', [ShootFile::STAGE_COMPLETED, ShootFile::STAGE_VERIFIED, 'completed', 'verified'])
                ->orderBy('id')
                ->first();

        if (!$video) {
            return null;
        }

        foreach (['path', 'storage_path', 'web_path'] as $field) {
            $raw = $video->{$field} ?? null;
            if (is_string($raw) && str_starts_with($raw, 'http') && preg_match('/\.mp4([?#]|$)/i', $raw)) {
                return $raw;
            }
            $key = $this->media->normalizeKey(is_string($raw) ? $raw : null);
            if ($key && $this->media->exists($key)) {
                return $this->media->publicUrl($key);
            }
        }

        return null;
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
